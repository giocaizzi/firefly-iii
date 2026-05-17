<?php

/**
 * UserTagsResource.php
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

namespace FireflyIII\Mcp\Resources;

use FireflyIII\Mcp\Support\JsonApiReducer;
use FireflyIII\Repositories\Tag\TagRepositoryInterface;
use FireflyIII\Transformers\TagTransformer;
use FireflyIII\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;
use League\Fractal\Manager;
use League\Fractal\Resource\Collection as FractalCollection;
use League\Fractal\Serializer\JsonApiSerializer;

/**
 * Authenticated user's tag library (WIP_MCP D-011, §5.2).
 *
 * Scoped to auth()->user() (C9). TagTransformer needs no enrichment — output runs
 * straight through JsonApiReducer (D-034, drop nulls per D-035).
 */
#[Uri('firefly-iii://user/tags')]
#[MimeType('application/json')]
#[Description("The authenticated user's tag library.")]
final class UserTagsResource extends Resource
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $repository = app(TagRepositoryInterface::class);
        $repository->setUser($user);

        $tags = $repository->get();

        $manager = new Manager();
        $manager->setSerializer(new JsonApiSerializer());

        $transformer = app(TagTransformer::class);
        $resource    = new FractalCollection($tags, $transformer, 'tags');

        $document = $manager->createData($resource)->toArray();
        $reduced  = app(JsonApiReducer::class)->reduce($document);

        return Response::text(json_encode($reduced, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
