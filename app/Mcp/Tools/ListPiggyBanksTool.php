<?php

/*
 * ListPiggyBanksTool.php
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

use FireflyIII\Repositories\PiggyBank\PiggyBankRepositoryInterface;
use FireflyIII\Transformers\PiggyBankTransformer;
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

#[Description("List the authenticated user's piggy banks (savings goals).")]
#[IsReadOnly]
#[IsIdempotent]
final class ListPiggyBanksTool extends AbstractMcpTool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user       = auth()->user();
        $repository = app(PiggyBankRepositoryInterface::class);
        $repository->setUser($user);

        [$page, $limit] = $this->pageAndLimit($request);

        $collection = $repository->getPiggyBanks();
        $count      = $collection->count();
        $offset     = ($page - 1) * $limit;
        $piggyBanks = $collection->slice($offset, $limit);

        $paginator = new LengthAwarePaginator($piggyBanks, $count, $limit, $page);

        $transformer = app(PiggyBankTransformer::class);
        $resource    = new FractalCollection($piggyBanks, $transformer, 'piggy_banks');
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
            'page'          => $schema->integer()->description('Page number (1-based).'),
            'limit'         => $schema->integer()->description("Items per page; defaults to the user's listPageSize preference."),
            'verbose'       => $schema->boolean()->description('Return the raw JSON:API document instead of the reduced shape.'),
            'include_nulls' => $schema->boolean()->description('Preserve null-valued attributes in the reduced output.')
        ];
    }
}
