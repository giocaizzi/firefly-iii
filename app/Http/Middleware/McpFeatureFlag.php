<?php

/*
 * McpFeatureFlag.php
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

namespace FireflyIII\Http\Middleware;

use Closure;
use FireflyIII\Support\Facades\FireflyConfig;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Gate the MCP route on the `allow_mcp` feature flag.
 *
 * Mirrors the webhook precedent in app/Api/V1/Controllers/Webhook/StoreController.php:66-70.
 * Disabling the flag makes the route indistinguishable from a 404.
 */
final class McpFeatureFlag
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (false === FireflyConfig::get('allow_mcp', config('firefly.allow_mcp'))->data) {
            throw new NotFoundHttpException('MCP endpoint is not enabled.');
        }

        return $next($request);
    }
}
