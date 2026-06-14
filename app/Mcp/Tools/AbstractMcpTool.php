<?php

/*
 * AbstractMcpTool.php
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

use FireflyIII\Mcp\Support\JsonApiReducer;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use League\Fractal\Manager;
use League\Fractal\Serializer\JsonApiSerializer;

/**
 * Shared base for all Firefly III MCP tools.
 *
 * Provides the canonical JSON:API -> reducer -> structured-response pipeline plus the
 * shared `verbose` / `include_nulls` / `page` / `limit` argument handling.
 */
abstract class AbstractMcpTool extends Tool
{
    /**
     * Fractal Manager configured to match the project's REST API (see Controller::getManager()).
     */
    protected function jsonApiManager(null|string $baseUrl = null): Manager
    {
        $manager = new Manager();
        $url     = $baseUrl ?? sprintf('%s/api/v1', request()->getSchemeAndHttpHost());
        $manager->setSerializer(new JsonApiSerializer($url));

        return $manager;
    }

    /**
     * Resolve `page` and `limit` from MCP tool args, falling back to the user's listPageSize preference.
     *
     * @return array{0: int, 1: int}
     */
    protected function pageAndLimit(Request $request): array
    {
        $page = (int) $request->get('page', 1);
        $page = min(max(1, $page), 2 ** 16);

        $limit = $request->get('limit');
        if (null === $limit) {
            /** @var null|User $user */
            $user  = auth()->user();
            $limit = null === $user ? 50 : (int) Preferences::getForUser($user, 'listPageSize', 50)->data;
        }
        $limit = min(max(1, (int) $limit), 2 ** 16);

        return [$page, $limit];
    }

    /** Default reducer; subclasses override to compact their domain shape. */
    protected function reducer(): JsonApiReducer
    {
        return new JsonApiReducer();
    }

    /**
     * Apply the reducer (unless `verbose=true`) and emit a Response carrying the JSON document.
     *
     * Uses Response::json() so the text content is the JSON payload. Tools that also declare
     * an outputSchema may wrap this Response with Response::structured() in their own handle().
     *
     * @param array<string, mixed> $jsonApiDocument
     */
    protected function respondJsonApi(array $jsonApiDocument, Request $request): Response
    {
        $verbose = (bool) $request->get('verbose', false);
        if ($verbose) {
            return Response::json($jsonApiDocument);
        }

        $includeNulls = (bool) $request->get('include_nulls', false);
        $reduced      = $this->reducer()->reduce($jsonApiDocument, ['include_nulls' => $includeNulls]);

        return Response::json($reduced);
    }
}
