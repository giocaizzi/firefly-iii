<?php

/*
 * AggregatesTransactions.php
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

use FireflyIII\Mcp\Support\Aggregates\TransactionAggregator;
use FireflyIII\Mcp\Support\InvalidArgument;
use FireflyIII\Support\Facades\Amount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

use function Safe\preg_match;

/**
 * `aggregate` option for transaction list tools: count and per-currency sums over the whole
 * result set (not just the page), optionally grouped, returned next to the rows as `aggregate`.
 *
 * Requires ProjectsTransactions (shape()) in the using tool.
 */
trait AggregatesTransactions
{
    /**
     * Validated aggregate options, or null when no aggregate was requested.
     *
     * @return null|array{group_by: null|string, include: list<int|string>, exclude: list<int|string>, rows: bool}
     */
    protected function aggregateOptions(Request $request): null|array
    {
        $keys      = ['group_by', 'include_categories', 'exclude_categories', 'rows'];
        $requested = (bool) $request->get('aggregate', false);
        foreach ($keys as $key) {
            $requested = $requested || null !== $request->get($key);
        }
        if (!$requested) {
            return null;
        }

        return [
            'group_by' => null === $request->get('group_by') ? null : $this->enumArg($request, 'group_by', TransactionAggregator::GROUP_BY, 'month'),
            'include'  => $this->categoryListArg($request, 'include_categories'),
            'exclude'  => $this->categoryListArg($request, 'exclude_categories'),
            'rows'     => (bool) $request->get('rows', true)
        ];
    }

    /**
     * Schema entries for the aggregate arguments.
     *
     * @return array<string, mixed>
     */
    protected function aggregateSchema(JsonSchema $schema): array
    {
        return [
            'aggregate'          => $schema
                ->boolean()
                ->description(
                    'Add `aggregate`: count and per-currency sums over ALL matches (every page). Sums are positive per type; net = deposit - withdrawal; transfers not in net.'
                ),
            'group_by'           => $schema
                ->string()
                ->enum(TransactionAggregator::GROUP_BY)
                ->description('Split the aggregate. account = own side (source; destination for deposits). Implies aggregate.'),
            'include_categories' => $schema
                ->array()
                ->items($schema->string())
                ->description('Aggregate only these categories (ids or names). Implies aggregate.'),
            'exclude_categories' => $schema
                ->array()
                ->items($schema->string())
                ->description('Leave these categories (ids or names) out of the aggregate. Implies aggregate.'),
            'rows'               => $schema->boolean()->description('false = aggregate only, no rows. Implies aggregate.')
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>>                                                     $groups
     * @param array{group_by: null|string, include: list<int|string>, exclude: list<int|string>, rows: bool} $options
     *
     * @return array<string, mixed>
     */
    protected function buildAggregate(Collection $groups, array $options): array
    {
        return new TransactionAggregator()->aggregate($groups, $options['group_by'], $options['include'], $options['exclude']);
    }

    /**
     * Rows (unless rows=false) plus the aggregate. Verbose output keeps the raw JSON:API document.
     *
     * @param null|array<string, mixed> $jsonApiDocument null when rows=false
     * @param array<string, mixed>      $aggregate
     * @param array<string, mixed>      $meta            extra meta for the aggregate-only shape
     */
    protected function respondWithAggregate(null|array $jsonApiDocument, array $aggregate, Request $request, array $meta = []): Response
    {
        if (null === $jsonApiDocument) {
            return Response::json(['aggregate' => $aggregate, 'meta' => ['primary_currency' => Amount::getPrimaryCurrency()->code, ...$meta]]);
        }
        if ((bool) $request->get('verbose', false)) {
            return Response::json([...$jsonApiDocument, 'aggregate' => $aggregate]);
        }

        return Response::json([...$this->withPagingHints($this->shape($jsonApiDocument, $request)), 'aggregate' => $aggregate]);
    }

    /**
     * @return list<int|string> digit strings become ids
     */
    private function categoryListArg(Request $request, string $key): array
    {
        $raw = $request->get($key);
        if (null === $raw) {
            return [];
        }
        if (is_string($raw) || is_int($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            InvalidArgument::fail($key, sprintf('%s must be a list of category ids or names.', $key), ['received' => $raw]);
        }
        $result = [];
        foreach ($raw as $entry) {
            if (is_string($entry) && 1 === preg_match('/^\d+$/', trim($entry))) {
                $entry = (int) trim($entry);
            }
            if (!is_int($entry) && (!is_string($entry) || '' === trim($entry))) {
                InvalidArgument::fail($key, sprintf('%s must be a list of category ids or names.', $key), ['received' => $raw]);
            }
            $result[] = is_string($entry) ? trim($entry) : $entry;
        }

        return $result;
    }
}
