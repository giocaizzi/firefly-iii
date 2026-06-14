<?php

/**
 * UserCategoriesResource.php
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
use FireflyIII\Repositories\Category\CategoryRepositoryInterface;
use FireflyIII\Support\JsonApi\Enrichments\CategoryEnrichment;
use FireflyIII\Transformers\CategoryTransformer;
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
 * Authenticated user's categories.
 *
 * Scoped to auth()->user() (C9). CategoryEnrichment hydrates the meta blob
 * CategoryTransformer expects (notes + spent/earned aggregates); output flows through
 * JsonApiReducer (drop nulls).
 */
#[Uri('firefly-iii://user/categories')]
#[MimeType('application/json')]
#[Description("The authenticated user's categories.")]
final class UserCategoriesResource extends Resource
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $repository = app(CategoryRepositoryInterface::class);
        $repository->setUser($user);

        $categories = $repository->getCategories();

        $enrichment = new CategoryEnrichment();
        $enrichment->setUser($user);
        $categories = $enrichment->enrich($categories);

        $manager = new Manager();
        $manager->setSerializer(new JsonApiSerializer());

        $transformer = app(CategoryTransformer::class);
        $resource    = new FractalCollection($categories, $transformer, 'categories');

        $document = $manager->createData($resource)->toArray();
        $reduced  = app(JsonApiReducer::class)->reduce($document);

        return Response::text(json_encode($reduced, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
