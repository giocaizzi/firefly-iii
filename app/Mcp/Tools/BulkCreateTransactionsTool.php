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
 * Bulk-create up to 100 transactions in a single DB transaction (WIP_MCP §4.5, D-026).
 *
 * Semantics: all-or-nothing — any single failing item rolls back the whole batch. Per-item
 * idempotency keys are honoured (D-027): a key that matches an already-stored journal for
 * the same user replays that journal instead of creating a duplicate.
 *
 * Audit log (D-030): one `info` line per successful or replayed item plus one summary line
 * at the end with totals. Failures are logged at `error` with the offending index.
 */
#[Description('Create up to 100 transactions atomically. Each item is a withdrawal, deposit, or transfer.')]
#[IsDestructive]
final class BulkCreateTransactionsTool extends AbstractMcpTool
{
    use WritesTransactions;

    public const int MAX_ITEMS = 100;

    public function handle(Request $request): Response
    {
        $items = $request->get('transactions');
        if (!is_array($items) || [] === $items) {
            return Response::error('transactions must be a non-empty array.');
        }
        if (count($items) > self::MAX_ITEMS) {
            return Response::error(sprintf('Maximum %d items per call.', self::MAX_ITEMS));
        }

        /** @var User $user */
        $user       = auth()->user();
        $storeRules = new StoreRequest()->rules();
        $itemErrors = [];

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

            $line    = $this->buildTransactionLine($type, $item);
            $payload = ['transactions' => [$line]];
            $errors  = $this->collectValidationErrors($payload, $storeRules);
            if ([] !== $errors) {
                $itemErrors[] = ['index' => $index, 'errors' => $errors];

                continue;
            }

            $preparedItems[$index] = ['payload' => $payload, 'idempotency_key' => $idempotencyKey];
        }

        if ([] !== $itemErrors) {
            $this->auditWriteFailure('validation', sprintf('%d items rejected pre-flight.', count($itemErrors)));

            return Response::error((string) json_encode(['errors' => $itemErrors]));
        }

        // All items passed pre-flight; open the DB transaction. Any failure inside the
        // closure (incl. an item-level exception) re-throws so the whole batch rolls back.
        $created   = 0;
        $replayed  = 0;
        $documents = [];

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
            'replayed' => $replayed
        ]);

        return Response::json([
            'data' => [
                'created'      => $created,
                'replayed'     => $replayed,
                'total'        => count($preparedItems),
                'transactions' => $documents
            ]
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $item = $schema->object([
            'type'             => $schema->string()->enum(['withdrawal', 'deposit', 'transfer'])->required()->description('Transaction type for this item.'),
            'source_id'        => $schema->integer()->description('Source account id.'),
            'source_name'      => $schema->string()->description('Source account name.'),
            'destination_id'   => $schema->integer()->description('Destination account id.'),
            'destination_name' => $schema->string()->description('Destination account name.'),
            'amount'           => $schema->string()->required()->description('Positive decimal amount as a string.'),
            'date'             => $schema->string()->required()->description('ISO-8601 date/time.'),
            'description'      => $schema->string()->required()->description('Description (1-1000 chars).'),
            'category_id'      => $schema->integer()->description('Category id.'),
            'category_name'    => $schema->string()->description('Category name.'),
            'budget_id'        => $schema->integer()->description('Budget id (withdrawals only).'),
            'budget_name'      => $schema->string()->description('Budget name.'),
            'tags'             => $schema->array()->items($schema->string())->description('Tag ids or names.'),
            'notes'            => $schema->string()->description('Free-form notes.'),
            'idempotency_key'  => $schema
                ->string()
                ->max(self::MAX_IDEMPOTENCY_KEY_LENGTH)
                ->description('Printable-ASCII key (max 128 chars) for replay deduplication.')
        ]);

        return [
            'transactions'  => $schema
                ->array()
                ->items($item)
                ->min(1)
                ->max(self::MAX_ITEMS)
                ->required()
                ->description(sprintf('Array of transaction items (1 to %d). The whole batch is rolled back if any item fails.', self::MAX_ITEMS)),
            'verbose'       => $schema->boolean()->description('Return the full JSON:API document per item instead of the reduced shape.'),
            'include_nulls' => $schema->boolean()->description('Keep null attribute values in the reduced shape.')
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
}
