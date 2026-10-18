<?php

/*
 * TransactionMatcher.php
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

namespace FireflyIII\Mcp\Support;

use Carbon\Carbon;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;

use function Safe\json_encode;

/**
 * Matches already-structured external rows (date, signed amount, account) against stored journals.
 *
 * Generic by design: no parsing and no provider knowledge. A row matches a journal either on a
 * caller-supplied reference compared with the journal's `external_id` / `internal_reference`
 * meta field, or on the account leg: same account, signed amount within the tolerance, date within
 * the window. The currency, when given, must be the leg's currency (compared on `amount`) or its
 * foreign currency (compared on `foreign_amount`).
 *
 * Assignment is one-to-one across the batch: a journal is claimed by at most one row. A row is
 * matched only when its best candidate is unique and the row is that candidate's unique best row
 * (score: date distance, then amount difference, then description match); ties stay ambiguous.
 * All candidates come from two queries per batch (account legs over the batch window, references).
 */
final class TransactionMatcher
{
    public const int MAX_CANDIDATES = 5;

    private const int SCALE = 12;

    /**
     * @param list<array{date: Carbon, amount: string, currency: null|string, account_ids: list<int>, reference: null|string, description: null|string, types: list<string>}> $rows
     *
     * @return list<array{status: string, match: null|array<string, mixed>, candidates: list<array<string, mixed>>, candidate_count: int}>
     */
    public function match(User $user, array $rows, int $windowDays, string $tolerance, string $referenceField): array
    {
        $references = $this->fetchReferences($user, $rows, $referenceField);
        $legs       = $this->fetchLegs($user, $rows, $windowDays);
        $results    = array_fill(0, count($rows), ['status' => 'unmatched', 'match' => null, 'candidates' => [], 'candidate_count' => 0]);
        $claimed    = [];
        $pending    = [];

        $refCounts = array_count_values(array_filter(
            array_map(static fn(array $row): string => (string) $row['reference'], $rows),
            static fn(string $ref): bool => '' !== $ref
        ));
        foreach ($rows as $index => $row) {
            $ref      = (string) $row['reference'];
            $journals = '' === $ref ? [] : $references[$ref] ?? [];
            if ([] === $journals) {
                $pending[] = $index;

                continue;
            }
            $candidates = array_map(static fn(array $journal): array => [...$journal, 'basis' => ['by' => $referenceField]], $journals);
            if (1 === count($candidates) && 1 === $refCounts[$ref] && !array_key_exists($candidates[0]['journal_id'], $claimed)) {
                $claimed[$candidates[0]['journal_id']] = $index;
                $results[$index]                       = $this->result('matched', $candidates[0], $candidates);

                continue;
            }
            $results[$index] = $this->result('ambiguous', null, $candidates);
        }

        // amount stage: best candidate per journal for every pending row.
        $options = [];
        foreach ($pending as $index) {
            $options[$index] = [];
            foreach ($legs as $leg) {
                $candidate = $this->legCandidate($rows[$index], $leg, $windowDays, $tolerance);
                if (null === $candidate || array_key_exists($leg['journal_id'], $claimed)) {
                    continue;
                }
                $existing = $options[$index][$leg['journal_id']] ?? null;
                if (null === $existing || $this->compare($candidate['score'], $existing['score']) < 0) {
                    $options[$index][$leg['journal_id']] = $candidate;
                }
            }
        }

        do {
            $changed = false;
            foreach ($pending as $position => $index) {
                $available = array_diff_key($options[$index], $claimed);
                if ([] === $available) {
                    continue;
                }
                uasort($available, fn(array $a, array $b): int => $this->compare($a['score'], $b['score']));
                $best   = reset($available);
                $second = next($available);
                if (false !== $second && 0 === $this->compare($best['score'], $second['score'])) {
                    continue;
                }
                if (!$this->isUniqueBestRow($index, $best, $pending, $options)) {
                    continue;
                }
                $claimed[$best['journal_id']] = $index;
                $results[$index]              = $this->result('matched', $best, array_values($available));
                unset($pending[$position]);
                $changed = true;
            }
        } while ($changed);

        foreach ($pending as $index) {
            $available = array_diff_key($options[$index], $claimed);
            if ([] === $available) {
                continue;
            }
            uasort($available, fn(array $a, array $b): int => $this->compare($a['score'], $b['score']));
            $results[$index] = $this->result('ambiguous', null, array_values($available));
        }

        return $results;
    }

    /**
     * @param array{0: int, 1: string, 2: int} $a
     * @param array{0: int, 1: string, 2: int} $b
     */
    private function compare(array $a, array $b): int
    {
        return [$a[0], bccomp($a[1], $b[1], self::SCALE), $a[2]] <=> [$b[0], 0, $b[2]];
    }

    /**
     * Account legs touching any row account, over the whole batch window, in one query.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function fetchLegs(User $user, array $rows, int $windowDays): array
    {
        $accountIds = array_values(array_unique(array_merge(...array_map(static fn(array $row): array => $row['account_ids'], $rows))));
        $types      = array_values(array_unique(array_merge(...array_map(static fn(array $row): array => $row['types'], $rows))));
        if ([] === $accountIds || [] === $types) {
            return [];
        }
        $dates = array_map(static fn(array $row): int => $row['date']->getTimestamp(), $rows);
        $start = Carbon::createFromTimestamp(min($dates), config('app.timezone'))->subDays($windowDays)->startOfDay();
        $end   = Carbon::createFromTimestamp(max($dates), config('app.timezone'))->addDays($windowDays)->endOfDay();

        $legs = DB::table('transactions')
            ->join('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
            ->join('transaction_types', 'transaction_types.id', '=', 'transaction_journals.transaction_type_id')
            ->leftJoin('transaction_currencies as currency', 'currency.id', '=', 'transactions.transaction_currency_id')
            ->leftJoin('transaction_currencies as foreign_currency', 'foreign_currency.id', '=', 'transactions.foreign_currency_id')
            ->whereNull('transactions.deleted_at')
            ->whereNull('transaction_journals.deleted_at')
            ->where('transaction_journals.user_group_id', $user->user_group_id)
            ->whereIn('transactions.account_id', $accountIds)
            ->whereIn('transaction_types.type', $types)
            ->where('transaction_journals.date', '>=', $start->format('Y-m-d H:i:s'))
            ->where('transaction_journals.date', '<=', $end->format('Y-m-d H:i:s'))
            ->get([
                'transactions.transaction_journal_id',
                'transaction_journals.transaction_group_id',
                'transaction_journals.date',
                'transaction_journals.description',
                'transaction_types.type',
                'transactions.account_id',
                'transactions.amount',
                'transactions.foreign_amount',
                'currency.code as currency_code',
                'foreign_currency.code as foreign_currency_code'
            ]);

        $result = [];
        foreach ($legs as $leg) {
            $result[] = [
                'journal_id'       => (int) $leg->transaction_journal_id,
                'group_id'         => (int) $leg->transaction_group_id,
                'day'              => substr((string) $leg->date, 0, 10),
                'description'      => (string) $leg->description,
                'type'             => (string) $leg->type,
                'account_id'       => (int) $leg->account_id,
                'amount'           => (string) $leg->amount,
                'foreign_amount'   => null === $leg->foreign_amount ? null : (string) $leg->foreign_amount,
                'currency'         => $leg->currency_code,
                'foreign_currency' => $leg->foreign_currency_code
            ];
        }

        return $result;
    }

    /**
     * Journals carrying any of the rows' references in `$field`, keyed by reference.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, list<array{journal_id: int, group_id: int}>>
     */
    private function fetchReferences(User $user, array $rows, string $field): array
    {
        $encoded = [];
        foreach ($rows as $row) {
            if (null !== $row['reference'] && '' !== $row['reference']) {
                $encoded[json_encode($row['reference'])] = $row['reference'];
            }
        }
        if ([] === $encoded) {
            return [];
        }

        $found = DB::table('journal_meta')
            ->join('transaction_journals', 'transaction_journals.id', '=', 'journal_meta.transaction_journal_id')
            ->where('journal_meta.name', $field)
            ->whereIn('journal_meta.data', array_keys($encoded))
            ->whereNull('journal_meta.deleted_at')
            ->whereNull('transaction_journals.deleted_at')
            ->where('transaction_journals.user_group_id', $user->user_group_id)
            ->orderBy('transaction_journals.id')
            ->get(['journal_meta.data', 'transaction_journals.id', 'transaction_journals.transaction_group_id']);

        $result = [];
        foreach ($found as $item) {
            $result[$encoded[(string) $item->data]][] = ['journal_id' => (int) $item->id, 'group_id' => (int) $item->transaction_group_id];
        }

        return $result;
    }

    /**
     * True when no other pending row scores `$candidate`'s journal as well as or better than `$index` does.
     *
     * @param array<string, mixed>                       $candidate
     * @param array<int, int>                            $pending
     * @param array<int, array<int, array<string, mixed>>> $options
     */
    private function isUniqueBestRow(int $index, array $candidate, array $pending, array $options): bool
    {
        foreach ($pending as $other) {
            $rival = $options[$other][$candidate['journal_id']] ?? null;
            if ($other !== $index && null !== $rival && $this->compare($rival['score'], $candidate['score']) <= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Candidate for one row on one account leg, or null when account, type, date, currency or amount differ.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $leg
     *
     * @return null|array<string, mixed>
     */
    private function legCandidate(array $row, array $leg, int $windowDays, string $tolerance): null|array
    {
        if (!in_array($leg['account_id'], $row['account_ids'], true) || !in_array($leg['type'], $row['types'], true)) {
            return null;
        }
        $distance = (int) abs(Carbon::parse($leg['day'])->diffInDays(Carbon::parse($row['date']->format('Y-m-d')), false));
        if ($distance > $windowDays) {
            return null;
        }

        $field  = 'amount';
        $amount = $leg['amount'];
        if (null !== $row['currency'] && $row['currency'] !== $leg['currency']) {
            if ($row['currency'] !== $leg['foreign_currency'] || null === $leg['foreign_amount']) {
                return null;
            }
            $field   = 'foreign_amount';
            $foreign = ltrim((string) $leg['foreign_amount'], '-');
            $amount  = -1 === bccomp($leg['amount'], '0', self::SCALE) ? '-' . $foreign : $foreign;
        }
        $difference = ltrim(bcsub($amount, $row['amount'], self::SCALE), '-');
        if (1 === bccomp($difference, $tolerance, self::SCALE)) {
            return null;
        }

        $descriptionMatch = false;
        $description      = mb_strtolower(trim((string) $row['description']));
        $legDescription   = mb_strtolower(trim($leg['description']));
        if ('' !== $description && '' !== $legDescription) {
            $descriptionMatch = str_contains($legDescription, $description) || str_contains($description, $legDescription);
        }

        $basis = ['by' => 'amount', 'account_id' => $leg['account_id'], 'date_distance_days' => $distance];
        if (0 !== bccomp($difference, '0', self::SCALE)) {
            $basis['amount_difference'] = TransactionProjector::trimAmount($difference);
        }
        if ('foreign_amount' === $field) {
            $basis['amount_field'] = $field;
        }
        if ($descriptionMatch) {
            $basis['description_match'] = true;
        }

        return [
            'journal_id' => $leg['journal_id'],
            'group_id'   => $leg['group_id'],
            'basis'      => $basis,
            'score'      => [$distance, $difference, $descriptionMatch ? 0 : 1]
        ];
    }

    /**
     * @param null|array<string, mixed>  $match
     * @param list<array<string, mixed>> $candidates sorted best first
     *
     * @return array{status: string, match: null|array<string, mixed>, candidates: list<array<string, mixed>>, candidate_count: int}
     */
    private function result(string $status, null|array $match, array $candidates): array
    {
        $strip = static function (array $candidate): array {
            unset($candidate['score']);

            return $candidate;
        };

        return [
            'status'          => $status,
            'match'           => null === $match ? null : $strip($match),
            'candidates'      => array_map($strip, array_slice($candidates, 0, self::MAX_CANDIDATES)),
            'candidate_count' => count($candidates)
        ];
    }
}
