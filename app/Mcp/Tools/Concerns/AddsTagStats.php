<?php

/*
 * AddsTagStats.php
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

use FireflyIII\Mcp\Support\Aggregates\TagStats;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

/**
 * `with_stats=true` for tag tools: adds a `stats` attribute to every tag in the JSON:API
 * document, so it shows up in every output mode.
 */
trait AddsTagStats
{
    /**
     * @return array<string, mixed>
     */
    protected function tagStatsSchema(JsonSchema $schema): array
    {
        return [
            'with_stats' => $schema->boolean()->description('Add count, first/last date, per-currency sums.')
        ];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function withTagStats(array $document, User $user, Request $request): array
    {
        if (!(bool) $request->get('with_stats', false) || !is_array($document['data'] ?? null)) {
            return $document;
        }
        $single = !array_is_list($document['data']);
        $items  = $single ? [$document['data']] : $document['data'];
        $stats  = new TagStats()->forTags($user, array_map(static fn(array $item): int => (int) $item['id'], $items));
        foreach ($items as $index => $item) {
            $items[$index]['attributes']['stats'] = $stats[(int) $item['id']] ?? ['count' => 0];
        }
        $document['data'] = $single ? $items[0] : $items;

        return $document;
    }
}
