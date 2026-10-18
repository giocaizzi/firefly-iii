<?php

/*
 * ApiBridge.php
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

namespace FireflyIII\Mcp\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

use function Safe\json_encode;

/**
 * Runs a request against Firefly III's own REST API (v1) inside the current MCP request.
 *
 * The sub-request goes through the API route's full middleware stack (auth, bindings, admin
 * checks) and its controller, FormRequest validation, repositories and transformers, so MCP
 * tools built on it apply exactly the same business rules as the REST API. The authenticated
 * user is the MCP caller; the original request is restored afterwards.
 */
final class ApiBridge
{
    /**
     * @param array<string, mixed>      $query
     * @param null|array<string, mixed> $json  JSON body
     * @param null|string               $raw   raw body (sent as application/octet-stream)
     */
    public function send(string $method, string $path, array $query = [], null|array $json = null, null|string $raw = null): SymfonyResponse
    {
        $app      = app();
        $original = $app->make('request');
        $uri      = sprintf('%s/api/v1/%s', $original->getSchemeAndHttpHost(), ltrim($path, '/'));
        $query    = array_filter($query, static fn(mixed $value): bool => null !== $value);
        if ([] !== $query) {
            $uri .= '?' . http_build_query(array_map(static fn(mixed $value): mixed => is_bool($value) ? ($value ? 'true' : 'false') : $value, $query));
        }
        $server  = ['HTTP_ACCEPT' => 'application/json'];
        $content = null;
        if (null !== $raw) {
            $server['CONTENT_TYPE'] = 'application/octet-stream';
            $content                = $raw;
        }
        if (null === $raw && in_array($method, ['POST', 'PUT'], true)) {
            $server['CONTENT_TYPE'] = 'application/json';
            $content                = json_encode([] === ($json ?? []) ? new \stdClass() : $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // createFromBase() exposes a JSON body as request input, as for a real HTTP request.
        $request = Request::createFromBase(Request::create($uri, $method, [], [], [], $server, $content));
        $request->setUserResolver($original->getUserResolver());

        // the HTTP kernel registers the middleware groups (api, api-admin) on the router; outside an HTTP request it may not be built yet.
        $app->make(Kernel::class);
        $app->instance('request', $request);
        Facade::clearResolvedInstance('request');

        try {
            return $app->make(Router::class)->dispatch($request);
        } catch (Throwable $e) {
            // thrown before the route pipeline (e.g. no matching route); render it the way the API would.
            return $app->make(ExceptionHandler::class)->render($request, $e);
        } finally {
            $app->instance('request', $original);
            Facade::clearResolvedInstance('request');
        }
    }
}
