<?php

/*
 * BulkCreateTransactionsTool.php
 * Copyright (c) 2026 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Mcp\Tools;

use FireflyIII\Api\V1\Requests\Models\Transaction\StoreRequest;
use FireflyIII\Mcp\Support\CreatedEntities;
use FireflyIII\Mcp\Tools\Concerns\MatchesTransactions;
use FireflyIII\Mcp\Tools\Concerns\ProjectsTransactions;
use FireflyIII\Mcp\Tools\Concerns\WritesTransactions;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Throwable;

use function Safe\json_encode;

/**
 * Bulk-create up to 100 transactions in a single DB transaction.
 *
 * Semantics: all-or-nothing — any single failing item rolls back the whole batch. Per-item
 * idempotency keys are honoured: a key that matches an already-stored journal for
 * the same user replays that journal instead of creating a duplicate. Because the batch is
 * atomic, a client that timed out can resend the identical batch: if the first call committed,
 * every keyed item replays (`replayed: true`, the original transaction); if it rolled back,
 * the items are created now.
 *
 * Audit log: one `info` line per successful or replayed item plus one summary line
 * at the end with totals. Failures are logged at `error` with the offending index.
 *
 * Import support (all off by default, so a plain call behaves as before): `dry_run` returns the
 * plan (create / replay / skip with reasons) without writing; `skip_duplicates` leaves out items
 * that already exist, by `external_id` or, with `duplicate_check=amount_date_account`, by signed
 * amount on the item's asset account within `date_window_days`.
 */
#[Description('Create up to 100 withdrawals/deposits/transfers atomically. dry_run previews; skip_duplicates skips existing; resent idempotency_keys replay, none duplicate.')]
#[IsDestructive(false)]
final class BulkCreateTransactionsTool extends AbstractMcpTool
{
    use MatchesTransactions;
    use ProjectsTransactions;
    use WritesTransactions;

    public const int MAX_ITEMS = 100;

    public function handle(Request $request): Response
    {
        $this->validateShapeArgs($request);
        $items = $request->get('transactions');
        if (!is_array($items) || [] === $items) {
            return Response::error('transactions must be a non-empty array.');
        }
        if (count($items) > self::MAX_ITEMS) {
            return Response::error(sprintf('Maximum %d items per call.', self::MAX_ITEMS));
        }

        $dryRun         = (bool) $request->get('dry_run', false);
        $skipDuplicates = (bool) $request->get('skip_duplicates', false);
        $duplicateCheck = $this->enumArg($request, 'duplicate_check', ['external_id', 'amount_date_account'], 'external_id');
        $windowDays     = $this->dateWindowArg($request);

        /** @var User $user */
        $user          = auth()->user();
        $storeRules    = new StoreRequest()->rules();
        $itemErrors    = [];
        $createMissing = $this->createMissingArg($request);

        // Pre-validate every item up front so we don't open a DB transaction unless every
        // line passes structural checks. Cross-currency + idempotency-key well-formedness
        // are checked here too to short-circuit before any write.
        $preparedItems = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $itemErrors[] = ['index' => $index, 'errors' => ['root' => 'item must be an object.']];

                continue;
            }
            $type = array_key_exists('type', $item) ? (string) $item['type'] : '';
            if (!in_array($type, ['withdrawal', 'deposit', 'transfer'], true)) {
                $itemErrors[] = ['index' => $index, 'errors' => ['type' => 'type must be one of withdrawal|deposit|transfer.']];

                continue;
            }

            $idempotencyKey = array_key_exists('idempotency_key', $item) ? (string) $item['idempotency_key'] : '';
            if (null !== $this->rejectInvalidIdempotencyKey($idempotencyKey)) {
                $itemErrors[] = ['index' => $index, 'errors' => ['idempotency_key' => 'must be printable ASCII and at most 128 chars.']];

                continue;
            }

            if ('transfer' === $type && null !== $this->rejectCrossCurrencyTransfer($item)) {
                $itemErrors[] = ['index' => $index, 'errors' => ['source_id' => 'Cross-currency transfers are not supported via MCP.']];

                continue;
            }

            $line    = $this->buildTransactionLine($type, $item, sprintf('transactions.%s.', $index));
            $payload = ['transactions' => [$line]];
            $errors  = $this->collectValidationErrors($payload, $storeRules);
            if ([] !== $errors) {
                $itemErrors[] = ['index' => $index, 'errors' => $errors];

                continue;
            }

            if (!$createMissing && [] !== ($missing = $this->missingNames($user, ucfirst($type), $line))) {
                $itemErrors[] = ['index' => $index, 'errors' => ['missing_names' => $missing]];

                continue;
            }

            $preparedItems[$index] = ['payload' => $payload, 'idempotency_key' => $idempotencyKey, 'item' => $item];
        }

        if ([] !== $itemErrors) {
            $this->auditWriteFailure('validation', sprintf('%d items rejected pre-flight.', count($itemErrors)));

            return Response::error((string) json_encode(['errors' => $itemErrors]));
        }

        $plan = [];
        if ($dryRun || $skipDuplicates) {
            $duplicates = $this->findDuplicates(
                array_map(static fn(array $prepared): array => $prepared['item'], $preparedItems),
                $duplicateCheck,
                $windowDays
            );
            $plan = $this->planItems($preparedItems, $duplicates, $skipDuplicates);
        }
        if ($dryRun) {
            return Response::json($this->dryRunResult($plan));
        }
        $skipped = array_values(array_filter($plan, static fn(array $entry): bool => 'skip' === $entry['action']));
        foreach ($skipped as $entry) {
            unset($preparedItems[$entry['index']]);
        }

        // All items passed pre-flight; open the DB transaction. Any failure inside the
        // closure (incl. an item-level exception) re-throws so the whole batch rolls back.
        $created   = 0;
        $replayed  = 0;
        $documents = [];
        $newNames  = CreatedEntities::mark($user);

        try {
            DB::transaction(function () use ($preparedItems, $user, &$created, &$replayed, &$documents): void {
                $repo = app(TransactionGroupRepositoryInterface::class);
                foreach ($preparedItems as $index => $prepared) {
                    $payload = $prepared['payload'];
                    $key     = (string) $prepared['idempotency_key'];

                    if ('' !== $key) {
                        $existing = $this->findIdempotentJournal($key);
                        if (null !== $existing && null !== $existing->transactionGroup) {
                            ++$replayed;
                            $documents[] = ['index' => $index, 'replayed' => true, 'document' => $this->buildGroupDocument($existing->transactionGroup)];
                            Log::channel('audit')->info('MCP write', [
                                'tool'              => $this->name(),
                                'user_id'           => auth()->id(),
                                'transaction_id'    => $existing->id,
                                'idempotent_replay' => true,
                                'bulk_index'        => $index
                            ]);

                            continue;
                        }
                    }

                    $payload['user']       = $user;
                    $payload['user_group'] = $user->userGroup;
                    $group                 = $repo->store($payload);
                    $journal               = $group->transactionJournals()->first();
                    if (null !== $journal && '' !== $key) {
                        $this->storeIdempotencyKey($journal, $key);
                    }
                    ++$created;
                    $documents[] = ['index' => $index, 'replayed' => false, 'document' => $this->buildGroupDocument($group)];
                    Log::channel('audit')->info('MCP write', [
                        'tool'              => $this->name(),
                        'user_id'           => auth()->id(),
                        'transaction_id'    => $journal?->id,
                        'idempotent_replay' => false,
                        'bulk_index'        => $index
                    ]);
                }
            });
        } catch (Throwable $e) {
            $this->auditWriteFailure('factory', $e->getMessage());

            return Response::error(sprintf('Bulk create failed; rolled back: %s', $e->getMessage()));
        }

        Log::channel('audit')->info('MCP bulk write', [
            'user_id'  => auth()->id(),
            'count'    => count($preparedItems),
            'created'  => $created,
            'replayed' => $replayed,
            ...($skipDuplicates ? ['skipped' => count($skipped)] : [])
        ]);

        $result = [
            'data' => [
                'created'      => $created,
                'replayed'     => $replayed,
                'total'        => count($preparedItems) + count($skipped),
                'transactions' => $documents
            ]
        ];
        $createdMeta = $this->createdMeta($newNames);
        if ($skipDuplicates) {
            $result['data']['skipped']       = count($skipped);
            $result['data']['skipped_items'] = array_map(static fn(array $entry): array => array_diff_key($entry, ['action' => true]), $skipped);
        }
        if (!(bool) $request->get('verbose', false)) {
            $meta  = [];
            $items = [];
            foreach ($documents as $entry) {
                $shaped  = $this->shape($entry['document'], $request);
                $meta    = $shaped['meta'] ?? $meta;
                $items[] = ['index' => $entry['index'], 'replayed' => $entry['replayed'], 'transaction' => $shaped['data'] ?? null];
            }
            $result['data']['transactions'] = $items;
            if ([] !== $meta) {
                $result['meta'] = $meta;
            }
        }
        if ([] !== $createdMeta) {
            $result['meta'] = [...($result['meta'] ?? []), ...$createdMeta];
        }

        return Response::json($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $item = $schema->object([
            'type'             => $schema->string()->enum(['withdrawal', 'deposit', 'transfer'])->required()->description('Type.'),
            'source_id'        => $schema->integer()->description('Source account id.'),
            'source_name'      => $schema->string()->description('Source account name.'),
            'destination_id'   => $schema->integer()->description('Destination account id.'),
            'destination_name' => $schema->string()->description('Destination account name.'),
            'amount'           => $schema->string()->required()->description('Positive decimal string.'),
            'date'             => $schema->string()->required()->description('YYYY-MM-DD or ISO 8601 date-time.'),
            'description'      => $schema->string()->required()->description('1-1000 chars.'),
            'category_id'      => $schema->integer()->description('Category id.'),
            'category_name'    => $schema->string()->description('Category name.'),
            'budget_id'        => $schema->integer()->description('Budget id (withdrawals only).'),
            'budget_name'      => $schema->string()->description('Budget name.'),
            'tags'             => $schema->array()->items($schema->string())->description('Tag ids or names.'),
            'notes'            => $schema->string()->description('Notes.'),
            'idempotency_key'  => $schema->string()->max(self::MAX_IDEMPOTENCY_KEY_LENGTH)->description('Retry dedup key (printable ASCII, max 128); a replay returns the original.'),
            ...$this->optionalLineSchema($schema)
        ]);

        return [
            'transactions'     => $schema
                ->array()
                ->items($item)
                ->min(1)
                ->max(self::MAX_ITEMS)
                ->required()
                ->description(sprintf('1-%d items; any failure rolls back the batch.', self::MAX_ITEMS)),
            ...$this->createMissingSchema($schema),
            'dry_run'          => $schema->boolean()->description('Plan only, no writes.'),
            'skip_duplicates'  => $schema->boolean()->description('Skip existing items.'),
            'duplicate_check'  => $schema->string()->enum(['external_id', 'amount_date_account'])->description('Default external_id.'),
            'date_window_days' => $schema->integer()->description(sprintf('Default 0, max %d.', self::MAX_DATE_WINDOW_DAYS)),
            ...$this->writeOutputSchema($schema)
        ];
    }

    /**
     * Validate `$input` against `$rules` and return field => message[] errors (empty array on success).
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $rules
     *
     * @return array<string, list<string>>
     */
    private function collectValidationErrors(array $input, array $rules): array
    {
        $validator = \Illuminate\Support\Facades\Validator::make($input, $rules);
        if (!$validator->fails()) {
            return [];
        }

        return $validator->errors()->toArray();
    }

    /**
     * Dry-run response: counts plus the per-item plan.
     *
     * @param list<array<string, mixed>> $plan
     *
     * @return array<string, mixed>
     */
    private function dryRunResult(array $plan): array
    {
        $counts = array_count_values(array_map(static fn(array $entry): string => $entry['action'], $plan));
        Log::channel('audit')->info('MCP bulk dry run', [
            'user_id' => auth()->id(),
            'count'   => count($plan),
            'create'  => $counts['create'] ?? 0,
            'replay'  => $counts['replay'] ?? 0,
            'skip'    => $counts['skip'] ?? 0
        ]);

        return [
            'data' => [
                'dry_run'      => true,
                'would_create' => $counts['create'] ?? 0,
                'would_replay' => $counts['replay'] ?? 0,
                'would_skip'   => $counts['skip'] ?? 0,
                'total'        => count($plan),
                'items'        => $plan
            ]
        ];
    }

    /**
     * Per-item action: `replay` (idempotency key already stored), `skip` (duplicate, with skip_duplicates)
     * or `create`. Duplicates found without skip_duplicates are reported on the `create` entry.
     *
     * @param array<int, array<string, mixed>>                                 $preparedItems
     * @param array<int, array{reason: string, transaction_ids: list<int>}> $duplicates
     *
     * @return list<array<string, mixed>>
     */
    private function planItems(array $preparedItems, array $duplicates, bool $skipDuplicates): array
    {
        $plan = [];
        foreach ($preparedItems as $index => $prepared) {
            $key      = (string) $prepared['idempotency_key'];
            $existing = '' === $key ? null : $this->findIdempotentJournal($key);
            if (null !== $existing && null !== $existing->transactionGroup) {
                $plan[] = ['index' => $index, 'action' => 'replay', 'transaction_id' => (int) $existing->transaction_group_id];

                continue;
            }
            $duplicate = $duplicates[$index] ?? null;
            if (null !== $duplicate && $skipDuplicates) {
                $plan[] = ['index' => $index, 'action' => 'skip', ...$duplicate];

                continue;
            }
            $entry = ['index' => $index, 'action' => 'create'];
            if (null !== $duplicate) {
                $entry['duplicate'] = $duplicate;
            }
            $plan[] = $entry;
        }

        return $plan;
    }
}
