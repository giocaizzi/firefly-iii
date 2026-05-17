<?php

/*
 * ListWebhooksTool.php
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

use FireflyIII\Repositories\Webhook\WebhookRepositoryInterface;
use FireflyIII\Support\Facades\FireflyConfig;
use FireflyIII\Transformers\WebhookTransformer;
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

#[Description("List the authenticated user's webhooks. Honors the global allow_webhooks feature flag.")]
#[IsReadOnly]
#[IsIdempotent]
final class ListWebhooksTool extends AbstractMcpTool
{
    public function handle(Request $request): Response
    {
        if (false === FireflyConfig::get('allow_webhooks', config('firefly.allow_webhooks'))->data) {
            return Response::error('Webhooks are not enabled on this Firefly III instance.');
        }

        /** @var User $user */
        $user       = auth()->user();
        $repository = app(WebhookRepositoryInterface::class);
        $repository->setUser($user);

        [$page, $limit] = $this->pageAndLimit($request);

        $collection = $repository->all();
        $count      = $collection->count();
        $offset     = ($page - 1) * $limit;
        $webhooks   = $collection->slice($offset, $limit);

        $paginator = new LengthAwarePaginator($webhooks, $count, $limit, $page);

        $transformer = app(WebhookTransformer::class);
        $resource    = new FractalCollection($webhooks, $transformer, 'webhooks');
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
