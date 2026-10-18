<?php

/*
 * ListTagsTool.php
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

use FireflyIII\Mcp\Tools\Concerns\AddsTagStats;
use FireflyIII\Repositories\Tag\TagRepositoryInterface;
use FireflyIII\Transformers\TagTransformer;
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

#[Description('List tags.')]
#[IsReadOnly]
#[IsIdempotent]
final class ListTagsTool extends AbstractMcpTool
{
    use AddsTagStats;

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user       = auth()->user();
        $repository = app(TagRepositoryInterface::class);
        $repository->setUser($user);

        [$page, $limit] = $this->pageAndLimit($request);

        $collection = $repository->get();
        $count      = $collection->count();
        $offset     = ($page - 1) * $limit;
        $tags       = $collection->slice($offset, $limit);

        $paginator = new LengthAwarePaginator($tags, $count, $limit, $page);

        $transformer = app(TagTransformer::class);
        $resource    = new FractalCollection($tags, $transformer, 'tags');
        $resource->setPaginator(new IlluminatePaginatorAdapter($paginator));

        $document = $this->withTagStats($this->jsonApiManager()->createData($resource)->toArray(), $user, $request);

        return $this->respondJsonApi($document, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->pagingSchema($schema),
            ...$this->tagStatsSchema($schema),
            ...$this->outputFlagsSchema($schema)
        ];
    }
}
