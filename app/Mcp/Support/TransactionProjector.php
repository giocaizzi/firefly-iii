<?php

/*
 * TransactionProjector.php
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

use function Safe\preg_match;

/**
 * Compact, token-lean projection of TransactionGroupTransformer JSON:API documents.
 *
 * One flat row per split:
 *   journal_id, date (YYYY-MM-DD, or
 *   "YYYY-MM-DD HH:MM[:SS]" when the time is not midnight), type, amount (no trailing zeros),
 *   currency (only when it differs from the primary currency), foreign_amount /
 *   foreign_currency (only when set), description (capped), source, destination, category,
 *   budget, bill, tags, notes.
 * Single-split groups are flattened to that row under `id` = the group id (the id every
 * transaction tool accepts), keeping `journal_id` only when it differs; multi-split groups
 * become {group_id, title, splits: [row, ...]} with `journal_id` on every split. Null and
 * empty values are omitted unless `include_nulls` is set.
 *
 * `fields` restricts every row to the listed keys (the ids are always kept). A field may
 * be a compact key or any raw split attribute from the transformer (e.g. "source_id", "iban").
 */
final class TransactionProjector
{
    public const int DEFAULT_DESCRIPTION_MAX = 120;

    private const string ELLIPSIS = '…';

    /**
     * Strip trailing zeros from a decimal string: "12.500000000000" -> "12.5", "3.000" -> "3".
     */
    public static function trimAmount(null|string $amount): null|string
    {
        if (null === $amount || !str_contains($amount, '.')) {
            return $amount;
        }

        return rtrim(rtrim($amount, '0'), '.');
    }

    /**
     * Cap a string at `$max` characters (multibyte-safe), marking the cut with an ellipsis. 0 = no cap.
     */
    public static function truncate(null|string $text, int $max): null|string
    {
        if (null === $text || $max <= 0 || mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, max(0, $max - 1)) . self::ELLIPSIS;
    }

    /**
     * @param array<string, mixed>      $document JSON:API document (single item or collection)
     * @param null|list<string>         $fields
     *
     * @return array<string, mixed> {data, meta?}
     */
    public function project(
        array $document,
        string $primaryCurrency,
        null|array $fields = null,
        int $descriptionMax = self::DEFAULT_DESCRIPTION_MAX,
        bool $includeNulls = false
    ): array {
        $data = $document['data'] ?? null;
        $map  = fn(array $item): array => $this->projectGroup($item, $primaryCurrency, $fields, $descriptionMax, $includeNulls);

        $result = [];
        if (is_array($data) && array_is_list($data)) {
            $result['data'] = array_map($map, $data);
        }
        if (is_array($data) && !array_is_list($data)) {
            $result['data'] = $map($data);
        }

        $meta                     = is_array($document['meta'] ?? null) ? $document['meta'] : [];
        $meta['primary_currency'] = $primaryCurrency;
        $result['meta']           = $meta;

        return $result;
    }

    /**
     * "2026-01-02T00:00:00+01:00" -> "2026-01-02"; "2026-01-02T14:30:00+01:00" -> "2026-01-02 14:30".
     */
    private function compactDate(mixed $date): mixed
    {
        if (!is_string($date) || 1 !== preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(?::(\d{2}))?/', $date, $matches)) {
            return $date;
        }
        $seconds = $matches[3] ?? '00';
        if ('00:00' === $matches[2] && '00' === $seconds) {
            return $matches[1];
        }

        return sprintf('%s %s%s', $matches[1], $matches[2], '00' === $seconds ? '' : ':' . $seconds);
    }

    /**
     * @param array<string, mixed> $item
     * @param null|list<string>    $fields
     *
     * @return array<string, mixed>
     */
    private function projectGroup(array $item, string $primaryCurrency, null|array $fields, int $descriptionMax, bool $includeNulls): array
    {
        $attributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : $item;
        $groupId    = (int) ($item['id'] ?? $attributes['id'] ?? 0);
        $splits     = is_array($attributes['transactions'] ?? null) ? array_values($attributes['transactions']) : [];

        if (count($splits) <= 1) {
            $split     = $splits[0] ?? [];
            $row       = $this->projectSplit($split, $primaryCurrency, $fields, $descriptionMax, $includeNulls);
            $journalId = $row['journal_id'] ?? null;
            unset($row['journal_id']);
            $ids = ['id' => $groupId];
            if (null !== $journalId && $journalId !== $groupId) {
                $ids['journal_id'] = $journalId;
            }

            return [...$ids, ...$row];
        }

        $group = ['group_id' => $groupId, 'title' => self::truncate($attributes['group_title'] ?? null, $descriptionMax)];
        $group = $this->prune($group, $includeNulls);

        $group['splits'] = array_map(fn(array $split): array => $this->projectSplit(
            $split,
            $primaryCurrency,
            $fields,
            $descriptionMax,
            $includeNulls
        ), $splits);

        return $group;
    }

    /**
     * @param array<string, mixed> $split
     * @param null|list<string>    $fields
     *
     * @return array<string, mixed>
     */
    private function projectSplit(array $split, string $primaryCurrency, null|array $fields, int $descriptionMax, bool $includeNulls): array
    {
        $currency      = $split['currency_code'] ?? null;
        $foreignAmount = self::trimAmount(null !== ($split['foreign_amount'] ?? null) ? (string) $split['foreign_amount'] : null);
        $hasForeign    = null !== $foreignAmount && '' !== $foreignAmount && 0 !== bccomp($foreignAmount, '0', 12);
        $tags          = $split['tags'] ?? [];

        $row = [
            'journal_id'       => null !== ($split['transaction_journal_id'] ?? null) ? (int) $split['transaction_journal_id'] : null,
            'date'             => $this->compactDate($split['date'] ?? null),
            'type'             => $split['type'] ?? null,
            'amount'           => self::trimAmount(null !== ($split['amount'] ?? null) ? (string) $split['amount'] : null),
            'currency'         => $currency === $primaryCurrency ? null : $currency,
            'foreign_amount'   => $hasForeign ? $foreignAmount : null,
            'foreign_currency' => $hasForeign ? $split['foreign_currency_code'] ?? null : null,
            'description'      => self::truncate($split['description'] ?? null, $descriptionMax),
            'source'           => $split['source_name'] ?? null,
            'destination'      => $split['destination_name'] ?? null,
            'category'         => $split['category_name'] ?? null,
            'budget'           => $split['budget_name'] ?? null,
            'bill'             => $split['bill_name'] ?? null,
            'tags'             => is_array($tags) ? array_values($tags) : $tags,
            'notes'            => $split['notes'] ?? null
        ];

        if (null !== $fields) {
            $selected = ['journal_id' => $row['journal_id']];
            foreach ($fields as $field) {
                if (array_key_exists($field, $row)) {
                    $selected[$field] = $row[$field];

                    continue;
                }
                if (array_key_exists($field, $split)) {
                    $selected[$field] = $split[$field];
                }
            }
            $row = $selected;
        }

        return $this->prune($row, $includeNulls);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function prune(array $row, bool $includeNulls): array
    {
        if ($includeNulls) {
            return $row;
        }

        return array_filter($row, static fn(mixed $value): bool => null !== $value && '' !== $value && [] !== $value);
    }
}
