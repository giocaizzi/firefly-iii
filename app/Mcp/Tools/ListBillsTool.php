<?php

/*
 * ListBillsTool.php
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

use FireflyIII\Repositories\Bill\BillRepositoryInterface;
use FireflyIII\Support\JsonApi\Enrichments\SubscriptionEnrichment;
use FireflyIII\Transformers\BillTransformer;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Pagination\LengthAwarePaginator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Resource\Collection as FractalCollection;

#[Description("List the authenticated user's bills (recurring expected expenses), optionally filtered to active ones only.")]
#[IsReadOnly]
#[IsIdempotent]
final class ListBillsTool extends AbstractMcpTool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user       = auth()->user();
        $repository = app(BillRepositoryInterface::class);
        $repository->setUser($user);

        [$page, $limit] = $this->pageAndLimit($request);

        $collection = true === $request->get('active') ? $repository->getActiveBills() : $repository->getBills();
        $count      = $collection->count();
        $offset     = ($page - 1) * $limit;
        $bills      = $collection->slice($offset, $limit);

        $enrichment = new SubscriptionEnrichment();
        $enrichment->setUser($user);
        $bills = $enrichment->enrich($bills);

        $paginator = new LengthAwarePaginator($bills, $count, $limit, $page);

        $transformer = app(BillTransformer::class);
        $resource    = new FractalCollection($bills, $transformer, 'bills');
        $resource->setPaginator(new IlluminatePaginatorAdapter($paginator));

        $document = $this->jsonApiManager()->createData($resource)->toArray();

        return $this->respondJsonApi($document, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'active'        => $schema->boolean()->description('When true, return only active bills.'),
            'page'          => $schema->integer()->description('Page number (1-based).'),
            'limit'         => $schema->integer()->description("Items per page; defaults to the user's listPageSize preference."),
            'verbose'       => $schema->boolean()->description('Return the raw JSON:API document instead of the reduced shape.'),
            'include_nulls' => $schema->boolean()->description('Preserve null-valued attributes in the reduced output.')
        ];
    }
}
