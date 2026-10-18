<?php

/*
 * TagStats.php
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
use FireflyIII\User;
use Illuminate\Support\Facades\DB;

/**
 * Usage statistics for a set of tags in one grouped query: transaction (journal) count,
 * first/last date and per-currency sums per type (CurrencyTotals sign convention).
 */
final class TagStats
{
    /**
     * @param list<int> $tagIds
     *
     * @return array<int, array<string, mixed>> tag id => stats; tags without transactions get count 0
     */
    public function forTags(User $user, array $tagIds): array
    {
        if ([] === $tagIds) {
            return [];
        }
        $rows = DB::table('tag_transaction_journal')
            ->join('transaction_journals', 'transaction_journals.id', '=', 'tag_transaction_journal.transaction_journal_id')
            ->join('transaction_types', 'transaction_types.id', '=', 'transaction_journals.transaction_type_id')
            ->join('transactions', static function ($join): void {
                $join->on('transactions.transaction_journal_id', '=', 'transaction_journals.id')->where('transactions.amount', '<', 0);
            })
            ->join('transaction_currencies', 'transaction_currencies.id', '=', 'transactions.transaction_currency_id')
            ->whereIn('tag_transaction_journal.tag_id', $tagIds)
            ->where('transaction_journals.user_id', $user->id)
            ->whereNull('transaction_journals.deleted_at')
            ->whereNull('transactions.deleted_at')
            ->groupBy(['tag_transaction_journal.tag_id', 'transaction_types.type', 'transaction_currencies.code', 'transaction_currencies.decimal_places'])
            ->get([
                'tag_transaction_journal.tag_id',
                'transaction_types.type',
                'transaction_currencies.code',
                'transaction_currencies.decimal_places',
                DB::raw('COUNT(DISTINCT transaction_journals.id) as journal_count'),
                DB::raw('SUM(transactions.amount) as sum_amount'),
                DB::raw('MIN(transaction_journals.date) as first_date'),
                DB::raw('MAX(transaction_journals.date) as last_date')
            ]);

        /** @var array<int, array{totals: CurrencyTotals, first: null|string, last: null|string}> $byTag */
        $byTag = [];
        foreach ($rows as $row) {
            $tagId         = (int) $row->tag_id;
            $byTag[$tagId] ??= ['totals' => new CurrencyTotals(), 'first' => null, 'last' => null];
            $byTag[$tagId]['totals']->add(
                (string) $row->type,
                (string) $row->code,
                (int) $row->decimal_places,
                $this->decimal($row->sum_amount),
                (int) $row->journal_count
            );
            $first                  = (string) $row->first_date;
            $last                   = (string) $row->last_date;
            $byTag[$tagId]['first'] = null === $byTag[$tagId]['first'] || $first < $byTag[$tagId]['first'] ? $first : $byTag[$tagId]['first'];
            $byTag[$tagId]['last']  = null === $byTag[$tagId]['last'] || $last > $byTag[$tagId]['last'] ? $last : $byTag[$tagId]['last'];
        }

        $result = [];
        foreach ($tagIds as $tagId) {
            if (!array_key_exists($tagId, $byTag)) {
                $result[$tagId] = ['count' => 0];

                continue;
            }
            $result[$tagId] = [
                'count'      => $byTag[$tagId]['totals']->count(),
                'first_date' => $this->day($byTag[$tagId]['first']),
                'last_date'  => $this->day($byTag[$tagId]['last']),
                'totals'     => $byTag[$tagId]['totals']->toArray()
            ];
        }

        return $result;
    }

    private function day(null|string $date): null|string
    {
        return null === $date ? null : Carbon::parse($date)->format('Y-m-d');
    }

    /**
     * SQLite returns SUM() over decimals as a float; MySQL and Postgres as a decimal string.
     */
    private function decimal(mixed $value): string
    {
        return is_float($value) ? sprintf('%.12F', $value) : (string) ($value ?? '0');
    }
}
