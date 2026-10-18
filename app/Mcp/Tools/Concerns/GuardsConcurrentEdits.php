<?php

/*
 * GuardsConcurrentEdits.php
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
use FireflyIII\Mcp\Support\InvalidArgument;
use FireflyIII\Models\TransactionGroup;
use Throwable;

/**
 * Optimistic concurrency for transaction writes: `if_unmodified_since` carries the `updated_at`
 * a client last read (list/search/get with fields=["updated_at"]). A transaction changed after
 * that moment is a conflict and is not written.
 */
trait GuardsConcurrentEdits
{
    /**
     * Conflict payload when the group changed after `$since` (second precision), null otherwise.
     *
     * @return null|array<string, mixed>
     */
    protected function editConflict(TransactionGroup $group, Carbon $since): null|array
    {
        if ($group->updated_at->getTimestamp() <= $since->getTimestamp()) {
            return null;
        }

        return [
            'error'               => 'conflict',
            'id'                  => $group->id,
            'updated_at'          => $group->updated_at->toAtomString(),
            'if_unmodified_since' => $since->toAtomString(),
            'message'             => 'The transaction changed after if_unmodified_since; re-read it and retry.'
        ];
    }

    /**
     * Parse an `if_unmodified_since` value (ISO 8601 date-time, as returned in `updated_at`).
     */
    protected function unmodifiedSince(mixed $raw, string $field): null|Carbon
    {
        if (null === $raw || is_string($raw) && '' === trim($raw)) {
            return null;
        }
        if (!is_string($raw)) {
            InvalidArgument::fail($field, sprintf('%s must be an ISO 8601 date-time (a transaction\'s updated_at).', $field), ['received' => $raw]);
        }

        try {
            return Carbon::parse(trim($raw), config('app.timezone'));
        } catch (Throwable) {
            InvalidArgument::fail($field, sprintf('%s must be an ISO 8601 date-time (a transaction\'s updated_at).', $field), ['received' => $raw]);
        }
    }
}
