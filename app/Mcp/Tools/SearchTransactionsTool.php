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
use FireflyIII\Mcp\Resources\SearchHelpResource;
use FireflyIII\Mcp\Support\InvalidArgument;
use FireflyIII\Mcp\Tools\Concerns\ProjectsTransactions;
use FireflyIII\Support\JsonApi\Enrichments\TransactionGroupEnrichment;
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

#[Description('Search transactions with the Firefly III query language (text plus operators like amount_more:50). Syntax: resource firefly-iii://help/search.')]
#[IsReadOnly]
#[IsIdempotent]
final class SearchTransactionsTool extends AbstractMcpTool
{
    use ProjectsTransactions {
        shape as private projectedShape;
    }

    /** @var list<string> */
    private array $warnings = [];

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = auth()->user();
        [$page, $limit] = $this->pageAndLimit($request);
        $this->validateShapeArgs($request);

        $query = (string) $request->get('query', '');
        $query = $this->maybeAppendDate($query, 'date_after', $this->dateArg($request, 'start'));
        $query = $this->maybeAppendDate($query, 'date_before', $this->dateArg($request, 'end'));

        if ('' === trim($query)) {
            InvalidArgument::fail('query', 'Search query is required.');
        }

        $searcher = app(SearchInterface::class);
        $searcher->setUser($user);
        $searcher->parseQuery($query);
        $searcher->setPage($page);
        $searcher->setLimit($limit);

        $groups = $searcher->searchTransactions();

        $this->warnings = array_map(static fn(array $operator): string => sprintf(
            'Unknown or invalid operator "%s:%s" was ignored; see %s.',
            $operator['type'],
            $operator['value'],
            SearchHelpResource::URI
        ), $searcher->getInvalidOperators());

        $enrichment = new TransactionGroupEnrichment();
        $enrichment->setUser($user);
        $transactions = $enrichment->enrich($groups->getCollection());

        $transformer = app(TransactionGroupTransformer::class);
        $resource    = new FractalCollection($transactions, $transformer, 'transactions');
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
            'query' => $schema->string()->required()->description('e.g. "coffee amount_more:5 date_after:2026-01-01".'),
            'start' => $schema->string()->format('date')->description('Adds date_after:YYYY-MM-DD unless the query has one.'),
            'end'   => $schema->string()->format('date')->description('Adds date_before:YYYY-MM-DD unless the query has one.'),
            ...$this->pagingSchema($schema),
            ...$this->transactionOutputSchema($schema)
        ];
    }

    /**
     * @param array<string, mixed> $jsonApiDocument
     *
     * @return array<string, mixed>
     */
    protected function shape(array $jsonApiDocument, Request $request): array
    {
        $result = $this->projectedShape($jsonApiDocument, $request);
        if ([] !== $this->warnings) {
            $result['meta']['warnings'] = $this->warnings;
        }

        return $result;
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
}
