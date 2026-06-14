<?php

/*
 * GetTagTool.php
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

use FireflyIII\Repositories\Tag\TagRepositoryInterface;
use FireflyIII\Transformers\TagTransformer;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use League\Fractal\Resource\Item;

#[Description('Get a single tag by id or tag string. Provide either `id` or `tag`; `id` wins when both are supplied.')]
#[IsReadOnly]
#[IsIdempotent]
final class GetTagTool extends AbstractMcpTool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user       = auth()->user();
        $repository = app(TagRepositoryInterface::class);
        $repository->setUser($user);

        $id        = $request->get('id');
        $tagString = $request->get('tag');
        $tag       = null;

        if (null !== $id) {
            $tag = $repository->find((int) $id);
        }
        if (null === $tag && is_string($tagString) && '' !== $tagString) {
            $tag = $repository->findByTag($tagString);
        }
        if (null === $tag) {
            return Response::error('Tag not found.');
        }

        $transformer = app(TagTransformer::class);
        $resource    = new Item($tag, $transformer, 'tags');

        $document = $this->jsonApiManager()->createData($resource)->toArray();

        return $this->respondJsonApi($document, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id'            => $schema->integer()->description('Tag id. Wins over `tag` when both are supplied.'),
            'tag'           => $schema->string()->description('Exact tag string; used when `id` is omitted.'),
            'verbose'       => $schema->boolean()->description('Return the raw JSON:API document instead of the reduced shape.'),
            'include_nulls' => $schema->boolean()->description('Preserve null-valued attributes in the reduced output.')
        ];
    }
}
