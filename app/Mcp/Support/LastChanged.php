<?php

/*
 * LastChanged.php
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
use FireflyIII\Models\AuditLogEntry;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;

/**
 * When a transaction group last changed. Rule actions (on import, apply-rule) edit a journal or
 * its notes without touching the group, but they write an audit log entry, so the effective
 * time is the latest of the group's updated_at, its journals' updated_at and the journals'
 * audit log entries. Transaction rows report it as `updated_at`, and `if_unmodified_since`
 * is checked against it.
 */
final class LastChanged
{
    /**
     * @param list<int> $groupIds
     *
     * @return array<int, Carbon> group id => last change
     */
    public static function forGroups(array $groupIds): array
    {
        if ([] === $groupIds) {
            return [];
        }
        $last = [];
        foreach (TransactionGroup::query()->whereIn('id', $groupIds)->get(['id', 'updated_at']) as $group) {
            $last[(int) $group->id] = $group->updated_at;
        }
        $groupOf = [];
        foreach (TransactionJournal::query()->whereIn('transaction_group_id', $groupIds)->get(['id', 'transaction_group_id', 'updated_at']) as $journal) {
            $groupId                    = (int) $journal->transaction_group_id;
            $groupOf[(int) $journal->id] = $groupId;
            $last[$groupId]             = self::later($last[$groupId] ?? null, $journal->updated_at);
        }
        if ([] === $groupOf) {
            return array_filter($last, static fn (null|Carbon $time): bool => null !== $time);
        }
        $audits = AuditLogEntry::query()
            ->where('auditable_type', TransactionJournal::class)
            ->whereIn('auditable_id', array_keys($groupOf))
            ->groupBy('auditable_id')
            ->selectRaw('auditable_id, MAX(created_at) AS last_change')
            ->toBase()
            ->get()
        ;
        foreach ($audits as $audit) {
            $groupId        = $groupOf[(int) $audit->auditable_id];
            $last[$groupId] = self::later($last[$groupId] ?? null, Carbon::parse((string) $audit->last_change, config('app.timezone')));
        }

        return array_filter($last, static fn (null|Carbon $time): bool => null !== $time);
    }

    /**
     * Replace each group's `updated_at` in a JSON:API transaction document with its last change.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public static function apply(array $document): array
    {
        $data = $document['data'] ?? null;
        if (!is_array($data) || [] === $data) {
            return $document;
        }
        $items = array_is_list($data) ? $data : [$data];
        $ids   = array_values(array_filter(array_map(static fn (mixed $item): int => is_array($item) ? (int) ($item['id'] ?? 0) : 0, $items), static fn (int $id): bool => $id > 0));
        $last  = self::forGroups($ids);
        $items = array_map(static function (mixed $item) use ($last): mixed {
            $id = is_array($item) ? (int) ($item['id'] ?? 0) : 0;
            if (array_key_exists($id, $last) && is_array($item['attributes'] ?? null)) {
                $item['attributes']['updated_at'] = $last[$id]->toAtomString();
            }

            return $item;
        }, $items);
        $document['data'] = array_is_list($data) ? $items : $items[0];

        return $document;
    }

    private static function later(null|Carbon $current, null|Carbon $candidate): null|Carbon
    {
        if (null === $current || null !== $candidate && $candidate->greaterThan($current)) {
            return $candidate;
        }

        return $current;
    }
}
