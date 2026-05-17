<?php

/*
 * WritesTransactions.php
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

namespace FireflyIII\Mcp\Tools\Concerns;

use Carbon\Carbon;
use FireflyIII\Api\V1\Requests\Models\Transaction\StoreRequest;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalMeta;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\Support\JsonApi\Enrichments\TransactionGroupEnrichment;
use FireflyIII\Transformers\TransactionGroupTransformer;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use League\Fractal\Resource\Item;
use Throwable;

use function Safe\json_encode;
use function Safe\preg_match;

/**
 * Shared logic for MCP write tools (WIP_MCP D-026, D-027, D-029, D-030, D-033).
 *
 * Concentrates: idempotency lookup/store, account resolution for the cross-currency
 * guard, FormRequest-rules-based validation, and JSON:API-document construction so
 * the create / update tools all emit the same response shape via `respondJsonApi()`.
 */
trait WritesTransactions
{
    public const string IDEMPOTENCY_META_NAME = 'mcp_idempotency_key';

    public const int MAX_IDEMPOTENCY_KEY_LENGTH = 128;

    /**
     * Audit-log a write failure (D-030) — no payload, just the stage and exception message.
     */
    protected function auditWriteFailure(string $stage, string $message): void
    {
        Log::channel('audit')->error('MCP write failed', [
            'tool'      => $this->name(),
            'user_id'   => auth()->id(),
            'stage'     => $stage,
            'exception' => $message
        ]);
    }

    /**
     * Build the JSON:API response document for a freshly created or replayed group.
     *
     * Mirrors the Transaction\StoreController + UpdateController pipeline (collector
     * → enrichment → TransactionGroupTransformer → Fractal Manager).
     *
     * @return array<string, mixed>
     */
    protected function buildGroupDocument(TransactionGroup $group): array
    {
        /** @var User $user */
        $user = auth()->user();

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($user)->setUserGroup($user->userGroup)->setTransactionGroup($group)->withAPIInformation();

        $selected = $collector->getGroups()->first();
        if (null === $selected) {
            // fall back to the eloquent-shape transformer if the collector lost it (e.g. test sqlite quirks).
            $transformer = app(TransactionGroupTransformer::class);
            $resource    = new Item($group, static fn(TransactionGroup $group): array => $transformer->transformObject($group), 'transactions');

            return $this->jsonApiManager()->createData($resource)->toArray();
        }

        $enrichment = new TransactionGroupEnrichment();
        $enrichment->setUser($user);
        $selected = $enrichment->enrichSingle($selected);

        /** @var TransactionGroupTransformer $transformer */
        $transformer = app(TransactionGroupTransformer::class);
        $resource    = new Item($selected, $transformer, 'transactions');

        return $this->jsonApiManager()->createData($resource)->toArray();
    }

    /**
     * Build the `transactions.*` line-item payload from a single MCP input line.
     *
     * Maps the MCP arg shape (with both `*_id` and `*_name` for relations per D-033)
     * onto the structure expected by `TransactionGroupRepositoryInterface::store()`.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    protected function buildTransactionLine(string $type, array $input): array
    {
        $line = [
            'type'             => $type,
            'date'             => $this->parseLineDate($input['date'] ?? null),
            'amount'           => (string) ($input['amount'] ?? ''),
            'description'      => array_key_exists('description', $input) ? (string) $input['description'] : null,
            'source_id'        => array_key_exists('source_id', $input) ? (int) $input['source_id'] : null,
            'source_name'      => array_key_exists('source_name', $input) ? (string) $input['source_name'] : null,
            'destination_id'   => array_key_exists('destination_id', $input) ? (int) $input['destination_id'] : null,
            'destination_name' => array_key_exists('destination_name', $input) ? (string) $input['destination_name'] : null,
            'category_id'      => array_key_exists('category_id', $input) ? (int) $input['category_id'] : null,
            'category_name'    => array_key_exists('category_name', $input) ? (string) $input['category_name'] : null,
            'budget_id'        => array_key_exists('budget_id', $input) ? (int) $input['budget_id'] : null,
            'budget_name'      => array_key_exists('budget_name', $input) ? (string) $input['budget_name'] : null,
            'notes'            => array_key_exists('notes', $input) ? (string) $input['notes'] : null,
            'tags'             => $this->normaliseTags($input['tags'] ?? null),
            'reconciled'       => false
        ];

        // strip null entries to leave the FormRequest's defaults intact.
        return array_filter($line, static fn(mixed $value): bool => null !== $value);
    }

    /**
     * Run the full create-transaction pipeline (validation → idempotency → store → audit → respond).
     *
     * Used by CreateWithdrawalTool, CreateDepositTool, CreateTransferTool (D-021). Transfer-only
     * cross-currency rejection happens inline so all three tools share the same audit-log line.
     */
    protected function createGroup(string $type, Request $request): Response
    {
        $input          = $request->toArray();
        $idempotencyKey = array_key_exists('idempotency_key', $input) ? (string) $input['idempotency_key'] : '';

        if (null !== ($invalid = $this->rejectInvalidIdempotencyKey($idempotencyKey))) {
            return $invalid;
        }

        if ('transfer' === $type) {
            if (null !== ($crossCurrency = $this->rejectCrossCurrencyTransfer($input))) {
                Log::channel('audit')->info('MCP write', [
                    'tool'              => $this->name(),
                    'user_id'           => auth()->id(),
                    'transaction_id'    => null,
                    'idempotent_replay' => false,
                    'rejected'          => 'cross_currency'
                ]);

                return $crossCurrency;
            }
        }

        $line         = $this->buildTransactionLine($type, $input);
        $payload      = ['transactions' => [$line]];
        $storeRequest = new StoreRequest();
        if (null !== ($invalidPayload = $this->validateAgainstRules($payload, $storeRequest->rules()))) {
            $this->auditWriteFailure('validation', 'Validation failed');

            return $invalidPayload;
        }

        if ('' !== $idempotencyKey) {
            $existingJournal = $this->findIdempotentJournal($idempotencyKey);
            if (null !== $existingJournal && null !== $existingJournal->transactionGroup) {
                Log::channel('audit')->info('MCP write', [
                    'tool'              => $this->name(),
                    'user_id'           => auth()->id(),
                    'transaction_id'    => $existingJournal->id,
                    'idempotent_replay' => true
                ]);

                return $this->respondJsonApi($this->buildGroupDocument($existingJournal->transactionGroup), $request);
            }
        }

        /** @var User $user */
        $user                  = auth()->user();
        $payload['user']       = $user;
        $payload['user_group'] = $user->userGroup;

        try {
            $group   = app(TransactionGroupRepositoryInterface::class)->store($payload);
            $journal = $group->transactionJournals()->first();
        } catch (Throwable $e) {
            $this->auditWriteFailure('factory', $e->getMessage());

            return Response::error(sprintf('Failed to create %s: %s', $type, $e->getMessage()));
        }

        if (null !== $journal && '' !== $idempotencyKey) {
            $this->storeIdempotencyKey($journal, $idempotencyKey);
        }

        Log::channel('audit')->info('MCP write', [
            'tool'              => $this->name(),
            'user_id'           => auth()->id(),
            'transaction_id'    => $journal?->id,
            'idempotent_replay' => false
        ]);

        return $this->respondJsonApi($this->buildGroupDocument($group), $request);
    }

    /**
     * Look up a previously-stored journal for this user with the given idempotency key.
     *
     * Queries `journal_meta` by raw column so the JSON-encoding mutator on the model is
     * matched (composite index `(name, data)` from migration 2026_05_17_121535).
     *
     * Returns null for fresh / cross-user keys (defensive isolation per D-027).
     */
    protected function findIdempotentJournal(string $idempotencyKey): null|TransactionJournal
    {
        $existing = DB::table('journal_meta')
            ->where('name', self::IDEMPOTENCY_META_NAME)
            ->where('data', json_encode($idempotencyKey))
            ->whereNull('deleted_at')
            ->first();

        if (null === $existing) {
            return null;
        }

        $journal = TransactionJournal::find($existing->transaction_journal_id);
        if (null === $journal) {
            return null;
        }

        if ($journal->user_id !== (int) auth()->id()) {
            return null;
        }

        return $journal;
    }

    /**
     * Normalise the MCP `tags` argument (int|string ids/names) into a deduped string array.
     *
     * Tag ids resolve client-side or via the factory; for the FormRequest they're just
     * strings. We dedup case-insensitively to match the existing tag-creation behaviour.
     *
     * @param mixed $tags
     *
     * @return list<string>|null
     */
    protected function normaliseTags(mixed $tags): null|array
    {
        if (!is_array($tags)) {
            return null;
        }

        $result = [];
        $seen   = [];
        foreach ($tags as $tag) {
            if (is_int($tag) || is_string($tag)) {
                $value = trim((string) $tag);
                if ('' === $value) {
                    continue;
                }
                $key = mb_strtolower($value);
                if (array_key_exists($key, $seen)) {
                    continue;
                }
                $seen[$key] = true;
                $result[]   = $value;
            }
        }

        return $result;
    }

    /**
     * Convert the raw MCP `date` argument into a Carbon instance.
     *
     * Matches the REST `StoreRequest::dateFromValue()` contract so the downstream
     * TransactionJournalFactory can call `setTimezone()` on the value. Empty input
     * stays as an empty string so the FormRequest's `required` rule still rejects it
     * cleanly during validation (an empty string surfaces a validation error rather
     * than a TypeError).
     */
    protected function parseLineDate(mixed $value): Carbon|string
    {
        if (!is_string($value) || '' === $value) {
            return '';
        }

        try {
            return new Carbon($value, config('app.timezone'));
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * Cross-currency guard for transfers (WIP_MCP D-029).
     *
     * Resolves source/destination by id within the authenticated user's accounts and
     * compares currency codes. Returns null when both accounts share a currency, or
     * when either side is name-only (the factory will then create / look up the
     * account and Firefly's existing same-currency invariant for transfers applies).
     *
     * @param array<string, mixed> $input
     */
    protected function rejectCrossCurrencyTransfer(array $input): null|Response
    {
        $sourceId      = array_key_exists('source_id', $input) ? (int) $input['source_id'] : null;
        $destinationId = array_key_exists('destination_id', $input) ? (int) $input['destination_id'] : null;
        if (null === $sourceId || null === $destinationId) {
            return null;
        }

        /** @var User $user */
        $user = auth()->user();

        /** @var AccountRepositoryInterface $accountRepo */
        $accountRepo = app(AccountRepositoryInterface::class);
        $accountRepo->setUser($user);

        $source      = $accountRepo->find($sourceId);
        $destination = $accountRepo->find($destinationId);
        if (null === $source || null === $destination) {
            return null;
        }

        $sourceCurrency      = $accountRepo->getAccountCurrency($source);
        $destinationCurrency = $accountRepo->getAccountCurrency($destination);
        if (null === $sourceCurrency || null === $destinationCurrency) {
            return null;
        }

        if ($sourceCurrency->code !== $destinationCurrency->code) {
            return Response::error('Cross-currency transfers are not supported via MCP. Use the REST API.');
        }

        return null;
    }

    /**
     * Reject idempotency keys outside the [printable ASCII, ≤128 chars] window.
     */
    protected function rejectInvalidIdempotencyKey(string $idempotencyKey): null|Response
    {
        if ('' === $idempotencyKey) {
            return null;
        }
        $length = strlen($idempotencyKey);
        if ($length > self::MAX_IDEMPOTENCY_KEY_LENGTH) {
            return Response::error(sprintf('idempotency_key must be at most %d characters.', self::MAX_IDEMPOTENCY_KEY_LENGTH));
        }
        // printable ASCII 0x20-0x7E only.
        if (1 !== preg_match('/\A[\x20-\x7E]+\z/', $idempotencyKey)) {
            return Response::error('idempotency_key must contain only printable ASCII characters (0x20-0x7E).');
        }

        return null;
    }

    /**
     * Persist an idempotency key onto the freshly created journal.
     */
    protected function storeIdempotencyKey(TransactionJournal $journal, string $idempotencyKey): void
    {
        TransactionJournalMeta::create([
            'transaction_journal_id' => $journal->id,
            'name'                   => self::IDEMPOTENCY_META_NAME,
            'data'                   => $idempotencyKey
        ]);
    }

    /**
     * Validate the MCP input against the existing FormRequest rules (D-018).
     *
     * Returns an error Response when validation fails so the tool can short-circuit.
     *
     * @param array<string, mixed> $input    must already be wrapped as ['transactions' => [[...]]]
     * @param array<string, mixed> $rules    the FormRequest's `rules()` array
     */
    protected function validateAgainstRules(array $input, array $rules): null|Response
    {
        $validator = \Illuminate\Support\Facades\Validator::make($input, $rules);
        if ($validator->fails()) {
            return Response::error((string) json_encode($validator->errors()->toArray()));
        }

        return null;
    }
}
