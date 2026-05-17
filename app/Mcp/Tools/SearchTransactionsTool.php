<?php

/*
 * SearchTransactionsTool.php
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

namespace FireflyIII\Mcp\Tools;

use Carbon\Carbon;
use FireflyIII\Support\Search\SearchInterface;
use FireflyIII\Transformers\TransactionGroupTransformer;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Resource\Collection as FractalCollection;

#[Description('Search transactions using the Firefly III query language; mirrors /api/v1/search/transactions.')]
#[IsReadOnly]
#[IsIdempotent]
final class SearchTransactionsTool extends AbstractMcpTool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = auth()->user();
        [$page, $limit] = $this->pageAndLimit($request);

        $query = (string) $request->get('query', '');
        $query = $this->maybeAppendDate($query, 'date_after', $this->parseDate($request->get('start')));
        $query = $this->maybeAppendDate($query, 'date_before', $this->parseDate($request->get('end')));

        if ('' === trim($query)) {
            return Response::error('Search query is required.');
        }

        $searcher = app(SearchInterface::class);
        $searcher->setUser($user);
        $searcher->parseQuery($query);
        $searcher->setPage($page);
        $searcher->setLimit($limit);

        $groups = $searcher->searchTransactions();

        $transformer = app(TransactionGroupTransformer::class);
        $resource    = new FractalCollection($groups->getCollection(), $transformer, 'transactions');
        $resource->setPaginator(new IlluminatePaginatorAdapter($groups));

        $document = $this->jsonApiManager()->createData($resource)->toArray();

        return $this->respondJsonApi($document, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query'         => $schema->string()->required()->description('Firefly III search query, e.g. "groceries amount_more:50 date_after:2026-01-01".'),
            'start'         => $schema
                ->string()
                ->format('date')
                ->description('Optional inclusive start date; injected as date_after:YYYY-MM-DD if not present in `query`.'),
            'end'           => $schema
                ->string()
                ->format('date')
                ->description('Optional inclusive end date; injected as date_before:YYYY-MM-DD if not present in `query`.'),
            'page'          => $schema->integer()->description('Page number (1-based).'),
            'limit'         => $schema->integer()->description("Items per page; defaults to the user's listPageSize preference."),
            'verbose'       => $schema->boolean()->description('Return the raw JSON:API document instead of the reduced shape.'),
            'include_nulls' => $schema->boolean()->description('Preserve null-valued attributes in the reduced output.')
        ];
    }

    private function maybeAppendDate(string $query, string $operator, null|Carbon $date): string
    {
        if (null === $date) {
            return $query;
        }
        if (str_contains($query, $operator . ':')) {
            return $query;
        }

        return rtrim($query) . ' ' . $operator . ':' . $date->format('Y-m-d');
    }

    private function parseDate(mixed $raw): null|Carbon
    {
        if (!is_string($raw) || '' === trim($raw)) {
            return null;
        }
        try {
            return Carbon::parse($raw, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
