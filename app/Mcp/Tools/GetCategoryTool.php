<?php

/*
 * GetCategoryTool.php
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

use FireflyIII\Repositories\Category\CategoryRepositoryInterface;
use FireflyIII\Transformers\CategoryTransformer;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use League\Fractal\Resource\Item;

#[Description('Get a single category by id or name. Provide either `id` or `name`; `id` wins when both are supplied.')]
#[IsReadOnly]
#[IsIdempotent]
final class GetCategoryTool extends AbstractMcpTool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user       = auth()->user();
        $repository = app(CategoryRepositoryInterface::class);
        $repository->setUser($user);

        $id       = $request->get('id');
        $name     = $request->get('name');
        $category = null;

        if (null !== $id) {
            $category = $repository->find((int) $id);
        }
        if (null === $category && is_string($name) && '' !== $name) {
            $category = $repository->findByName($name);
        }
        if (null === $category) {
            return Response::error('Category not found.');
        }

        $transformer = app(CategoryTransformer::class);
        $resource    = new Item($category, $transformer, 'categories');

        $document = $this->jsonApiManager()->createData($resource)->toArray();

        return $this->respondJsonApi($document, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id'            => $schema->integer()->description('Category id. Wins over `name` when both are supplied.'),
            'name'          => $schema->string()->description('Exact category name; used when `id` is omitted.'),
            'verbose'       => $schema->boolean()->description('Return the raw JSON:API document instead of the reduced shape.'),
            'include_nulls' => $schema->boolean()->description('Preserve null-valued attributes in the reduced output.')
        ];
    }
}
