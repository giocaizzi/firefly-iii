<?php

/*
 * TransactionAggregator.php
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

namespace FireflyIII\Mcp\Support\Aggregates;

use Carbon\Carbon;
use FireflyIII\Enums\TransactionTypeEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Count and per-currency sums over a whole collector result (every page), optionally grouped.
 *
 * Input is the collector's array groups (no enrichment, no transformer), so the cost is the
 * one collector query the caller already runs. Grouping by tag adds one pivot query per 500
 * journals; a journal with several tags counts once in each of its tag groups.
 */
final class TransactionAggregator
{
    public const array GROUP_BY = ['month', 'category', 'tag', 'source', 'destination', 'account', 'type'];

    public const string NONE = '(none)';

    private const int CHUNK = 500;

    /**
     * @param Collection<int, array<string, mixed>> $groups     collector array groups
     * @param list<int|string>                      $include    category ids or names (case-insensitive; an int also matches a numeric name); empty = all
     * @param list<int|string>                      $exclude    same format as $include
     *
     * @return array<string, mixed>
     */
    public function aggregate(Collection $groups, null|string $groupBy, array $include, array $exclude): array
    {
        $journals = [];
        foreach ($groups as $group) {
            foreach ($group['transactions'] as $journal) {
                if ($this->categoryAllowed($journal, $include, $exclude)) {
                    $journals[] = $journal;
                }
            }
        }

        $totals = new CurrencyTotals();
        foreach ($journals as $journal) {
            $this->addTo($totals, $journal);
        }
        $result = ['count' => $totals->count(), 'totals' => $totals->toArray()];
        if (null === $groupBy) {
            return $result;
        }

        $tags    = 'tag' === $groupBy ? $this->tagsByJournal(array_column($journals, 'transaction_journal_id')) : [];
        $buckets = [];
        foreach ($journals as $journal) {
            foreach ($this->keys($journal, $groupBy, $tags) as $id => $key) {
                $bucketKey           = sprintf('%s|%s', $id, $key);
                $buckets[$bucketKey] ??= ['key' => $key, 'id' => is_int($id) ? $id : null, 'totals' => new CurrencyTotals()];
                $this->addTo($buckets[$bucketKey]['totals'], $journal);
            }
        }

        $rows = array_map(static fn(array $bucket): array => array_filter(
            [
                'key'    => $bucket['key'],
                'id'     => $bucket['id'],
                'count'  => $bucket['totals']->count(),
                'totals' => $bucket['totals']->toArray()
            ],
            static fn(mixed $value): bool => null !== $value
        ), array_values($buckets));

        usort($rows, static fn(array $a, array $b): int => 'month' === $groupBy
            ? strcmp((string) $a['key'], (string) $b['key'])
            : [$b['count'], (string) $a['key']] <=> [$a['count'], (string) $b['key']]);

        $result['group_by'] = $groupBy;
        $result['groups']   = $rows;

        return $result;
    }

    /**
     * @param array<string, mixed> $journal
     */
    private function addTo(CurrencyTotals $totals, array $journal): void
    {
        $totals->add(
            (string) $journal['transaction_type_type'],
            (string) $journal['currency_code'],
            (int) $journal['currency_decimal_places'],
            (string) $journal['amount']
        );
    }

    /**
     * @param array<string, mixed> $journal
     * @param list<int|string>     $include
     * @param list<int|string>     $exclude
     */
    private function categoryAllowed(array $journal, array $include, array $exclude): bool
    {
        $id      = null === ($journal['category_id'] ?? null) ? null : (int) $journal['category_id'];
        $name    = null === ($journal['category_name'] ?? null) ? null : strtolower((string) $journal['category_name']);
        $matches = static function (array $list) use ($id, $name): bool {
            foreach ($list as $entry) {
                if (is_int($entry) && $entry === $id || strtolower((string) $entry) === $name) {
                    return true;
                }
            }

            return false;
        };
        if ([] !== $include && !$matches($include)) {
            return false;
        }

        return !$matches($exclude);
    }

    /**
     * Group keys for one journal: [id => label]; string ids mark keys without a database id.
     *
     * @param array<string, mixed>                $journal
     * @param array<int, array<int, string>>      $tags
     *
     * @return array<int|string, string>
     */
    private function keys(array $journal, string $groupBy, array $tags): array
    {
        $type = (string) $journal['transaction_type_type'];

        return match ($groupBy) {
            'month'       => ['m' => $journal['date'] instanceof Carbon ? $journal['date']->format('Y-m') : substr((string) $journal['date'], 0, 7)],
            'type'        => ['t' => CurrencyTotals::typeKey($type)],
            'category' => null === ($journal['category_id'] ?? null)
                ? ['none' => self::NONE]
                : [(int) $journal['category_id'] => (string) $journal['category_name']],
            'tag'         => $tags[(int) $journal['transaction_journal_id']] ?? ['none' => self::NONE],
            'source'      => [(int) $journal['source_account_id'] => (string) $journal['source_account_name']],
            'destination' => [(int) $journal['destination_account_id'] => (string) $journal['destination_account_name']],
            // the user's own side: money leaves it for withdrawals and transfers, enters it for deposits.
            'account' => TransactionTypeEnum::DEPOSIT->value === $type
                ? [(int) $journal['destination_account_id'] => (string) $journal['destination_account_name']]
                : [(int) $journal['source_account_id'] => (string) $journal['source_account_name']],
            default   => ['none' => self::NONE]
        };
    }

    /**
     * @param list<mixed> $journalIds
     *
     * @return array<int, array<int, string>> journal id => [tag id => tag]
     */
    private function tagsByJournal(array $journalIds): array
    {
        $result = [];
        foreach (array_chunk(array_map(intval(...), $journalIds), self::CHUNK) as $chunk) {
            $rows = DB::table('tag_transaction_journal')
                ->join('tags', 'tags.id', '=', 'tag_transaction_journal.tag_id')
                ->whereIn('tag_transaction_journal.transaction_journal_id', $chunk)
                ->whereNull('tags.deleted_at')
                ->get(['tag_transaction_journal.transaction_journal_id', 'tags.id', 'tags.tag']);
            foreach ($rows as $row) {
                $result[(int) $row->transaction_journal_id][(int) $row->id] = (string) $row->tag;
            }
        }

        return $result;
    }
}
