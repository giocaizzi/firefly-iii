<?php

/*
 * MatchesTransactions.php
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
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Mcp\Support\InvalidArgument;
use FireflyIII\Mcp\Support\TransactionMatcher;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\JsonApi\Enrichments\TransactionGroupEnrichment;
use FireflyIII\Transformers\TransactionGroupTransformer;
use FireflyIII\User;
use Laravel\Mcp\Request;
use League\Fractal\Resource\Collection as FractalCollection;

use function Safe\preg_match;

/**
 * Argument handling shared by the tools that match rows against stored transactions
 * (match-transactions, and the duplicate check of bulk-create-transactions).
 */
trait MatchesTransactions
{
    public const int MAX_DATE_WINDOW_DAYS = 90;

    /** @var array<string, string> */
    private const array MATCH_TYPES = [
        'withdrawal'      => TransactionTypeEnum::WITHDRAWAL->value,
        'deposit'         => TransactionTypeEnum::DEPOSIT->value,
        'transfer'        => TransactionTypeEnum::TRANSFER->value,
        'opening_balance' => TransactionTypeEnum::OPENING_BALANCE->value,
        'reconciliation'  => TransactionTypeEnum::RECONCILIATION->value
    ];

    /**
     * Resolve an account given by id (int or digit string) or exact name to its id; unknown accounts are rejected.
     */
    protected function accountRefId(mixed $ref, string $field): int
    {
        /** @var User $user */
        $user       = auth()->user();
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);
        if (is_string($ref) && 1 === preg_match('/^\d+$/', trim($ref))) {
            $ref = (int) trim($ref);
        }
        $account = null;
        if (is_int($ref)) {
            $account = $repository->find($ref);
        }
        if (is_string($ref) && '' !== trim($ref)) {
            $account = $repository->findByName(trim($ref), []);
        }
        if (null === $account) {
            InvalidArgument::fail($field, sprintf('%s must be an existing account id or exact name.', $field), ['received' => $ref]);
        }

        return (int) $account->id;
    }

    /**
     * The default account scope: every asset account of the user.
     *
     * @return list<int>
     */
    protected function assetAccountIds(): array
    {
        /** @var User $user */
        $user       = auth()->user();
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);

        return array_values(array_map(intval(...), $repository->getAccountsByType([AccountTypeEnum::ASSET->value])->pluck('id')->all()));
    }

    /**
     * Shared `date_window_days`: 0 (same day, the default) up to MAX_DATE_WINDOW_DAYS.
     */
    protected function dateWindowArg(Request $request): int
    {
        $raw = $request->get('date_window_days');
        if (null === $raw) {
            return 0;
        }
        if (is_string($raw) && 1 === preg_match('/^\d+$/', trim($raw))) {
            $raw = (int) trim($raw);
        }
        if (!is_int($raw) || $raw < 0 || $raw > self::MAX_DATE_WINDOW_DAYS) {
            InvalidArgument::fail('date_window_days', sprintf('date_window_days must be an integer from 0 to %d.', self::MAX_DATE_WINDOW_DAYS), [
                'received' => $raw
            ]);
        }

        return $raw;
    }

    /**
     * A decimal given as string or number ("-12.5", 12.5); `$signed` allows a leading minus. Anything else is rejected.
     */
    protected function decimalArg(mixed $raw, string $field, bool $signed): string
    {
        if (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
        }
        $pattern = $signed ? '/^-?\d+(\.\d+)?$/' : '/^\d+(\.\d+)?$/';
        if (!is_string($raw) || 1 !== preg_match($pattern, trim($raw))) {
            InvalidArgument::fail($field, sprintf('%s must be a %sdecimal number.', $field, $signed ? 'signed ' : 'non-negative '), ['received' => $raw]);
        }

        return trim($raw);
    }

    /**
     * Existing transactions duplicating bulk items, keyed by item index: {reason, transaction_ids}.
     *
     * `external_id`: an item duplicates a journal with the same external_id. `amount_date_account`
     * additionally matches the item's signed amount on its asset-side account within `$windowDays`
     * (one-to-one, see TransactionMatcher); a tie is reported as `possible_duplicate`.
     *
     * @param array<int, array<string, mixed>> $items index => validated bulk item
     *
     * @return array<int, array{reason: string, transaction_ids: list<int>}>
     */
    protected function findDuplicates(array $items, string $check, int $windowDays): array
    {
        $rows    = [];
        $indexes = [];
        foreach ($items as $index => $item) {
            $type       = (string) $item['type'];
            $reference  = '' === (string) ($item['external_id'] ?? '') ? null : (string) $item['external_id'];
            $accountIds = 'amount_date_account' === $check ? $this->itemAccountIds($type, $item) : [];
            if (null === $reference && [] === $accountIds) {
                continue;
            }
            $amount = ltrim(trim((string) $item['amount']), '-');
            $rows[] = [
                'date'        => new Carbon((string) $item['date'], config('app.timezone')),
                'amount'      => 'deposit' === $type ? $amount : '-' . $amount,
                'currency'    => null,
                'account_ids' => $accountIds,
                'reference'   => $reference,
                'description' => is_string($item['description'] ?? null) ? $item['description'] : null,
                'types'       => [self::MATCH_TYPES[$type]]
            ];
            $indexes[] = $index;
        }
        if ([] === $rows) {
            return [];
        }

        /** @var User $user */
        $user       = auth()->user();
        $duplicates = [];
        foreach (new TransactionMatcher()->match($user, $rows, $windowDays, '0', 'external_id') as $position => $result) {
            if ('unmatched' === $result['status']) {
                continue;
            }
            $candidates                      = null === $result['match'] ? $result['candidates'] : [$result['match']];
            $reason                          = 'amount' !== $candidates[0]['basis']['by']
                ? 'external_id'
                : ('matched' === $result['status'] ? 'amount_date_account' : 'possible_duplicate');
            $duplicates[$indexes[$position]] = [
                'reason'          => $reason,
                'transaction_ids' => array_values(array_unique(array_map(static fn(array $candidate): int => $candidate['group_id'], $candidates)))
            ];
        }

        return $duplicates;
    }

    /**
     * Shaped transaction rows (per the tool's output flags) for the given journals, keyed by group id.
     *
     * @param list<int> $journalIds
     *
     * @return array<int, mixed>
     */
    protected function groupRowsForJournals(array $journalIds, Request $request): array
    {
        if ([] === $journalIds) {
            return [];
        }

        /** @var User $user */
        $user      = auth()->user();
        $collector = app(GroupCollectorInterface::class);
        $collector
            ->setUser($user)
            ->setUserGroup($user->userGroup)
            ->setJournalIds(array_values(array_unique($journalIds)))
            ->withAPIInformation();

        $enrichment = new TransactionGroupEnrichment();
        $enrichment->setUser($user);
        $groups = $enrichment->enrich($collector->getGroups());

        $resource = new FractalCollection($groups, app(TransactionGroupTransformer::class), 'transactions');
        $document = $this->jsonApiManager()->createData($resource)->toArray();
        $items    = (bool) $request->get('verbose', false) ? $document['data'] ?? [] : $this->shape($document, $request)['data'] ?? [];

        $rows = [];
        foreach ($items as $item) {
            $rows[(int) ($item['id'] ?? $item['group_id'] ?? 0)] = $item;
        }

        return $rows;
    }

    /**
     * Shared `types` (list of withdrawal|deposit|transfer|opening_balance|reconciliation), as stored type names.
     *
     * @return list<string>
     */
    protected function matchTypesArg(Request $request): array
    {
        $raw = $request->get('types');
        if (null === $raw) {
            return [TransactionTypeEnum::WITHDRAWAL->value, TransactionTypeEnum::DEPOSIT->value, TransactionTypeEnum::TRANSFER->value];
        }
        $allowed = array_keys(self::MATCH_TYPES);
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw) || [] === $raw) {
            InvalidArgument::fail('types', 'types must be a non-empty list.', ['allowed' => $allowed, 'received' => $raw]);
        }
        $types = [];
        foreach ($raw as $type) {
            $key = is_string($type) ? strtolower(trim($type)) : '';
            if (!array_key_exists($key, self::MATCH_TYPES)) {
                InvalidArgument::fail('types', sprintf('types must contain only: %s.', implode(', ', $allowed)), ['allowed' => $allowed, 'received' => $raw]);
            }
            $types[] = self::MATCH_TYPES[$key];
        }

        return array_values(array_unique($types));
    }

    /**
     * The asset-side account of a bulk item (source for withdrawals and transfers, destination for
     * deposits) when it already exists; empty when it cannot be resolved.
     *
     * @param array<string, mixed> $item
     *
     * @return list<int>
     */
    private function itemAccountIds(string $type, array $item): array
    {
        /** @var User $user */
        $user       = auth()->user();
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);
        $side    = 'deposit' === $type ? 'destination' : 'source';
        $account = null;
        if (null !== ($item[$side . '_id'] ?? null)) {
            $account = $repository->find((int) $item[$side . '_id']);
        }
        if (null === $account && is_string($item[$side . '_name'] ?? null) && '' !== trim($item[$side . '_name'])) {
            $account = $repository->findByName(trim($item[$side . '_name']), [
                AccountTypeEnum::ASSET->value,
                AccountTypeEnum::LOAN->value,
                AccountTypeEnum::DEBT->value,
                AccountTypeEnum::MORTGAGE->value
            ]);
        }

        return null === $account ? [] : [(int) $account->id];
    }
}
