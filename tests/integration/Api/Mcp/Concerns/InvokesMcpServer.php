<?php

/*
 * InvokesMcpServer.php
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

namespace Tests\integration\Api\Mcp\Concerns;

use FireflyIII\Mcp\Servers\FireflyServer;
use FireflyIII\User;
use Laravel\Mcp\Server\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Convenience wrappers around the laravel/mcp in-process test harness.
 *
 * Centralises the `actingAs($user, 'api')` boilerplate and the JSON-payload extraction so
 * tests focus on asserting business outcomes rather than transport plumbing.
 */
trait InvokesMcpServer
{
    /**
     * Decode the first `content[0].text` payload returned by a tool invocation.
     *
     * Tools emit `Response::json(...)` which the harness wraps as a `text` block whose
     * body is the JSON document. Failing this decode means the tool produced something
     * unexpected, which we surface as an explicit assertion failure.
     *
     * @return array<string, mixed>
     */
    protected function decodeJson(TestResponse $response): array
    {
        $text = $this->responseText($response);
        Assert::assertJson($text, 'Tool did not produce a JSON payload.');

        /** @var array<string, mixed> */
        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $args
     */
    protected function invokeResource(User $user, string $resourceClass, array $args = []): TestResponse
    {
        return FireflyServer::actingAs($user, 'api')->resource($resourceClass, $args);
    }

    /**
     * @param array<string, mixed> $args
     */
    protected function invokeTool(User $user, string $toolClass, array $args = []): TestResponse
    {
        return FireflyServer::actingAs($user, 'api')->tool($toolClass, $args);
    }

    /**
     * Extract the first text payload across the supported primitive kinds (tool / resource).
     */
    protected function responseText(TestResponse $response): string
    {
        $accessor = \Closure::bind(static fn(TestResponse $instance): array => $instance->response->toArray(), null, TestResponse::class);
        $payload  = $accessor($response);

        // Tool response: result.content[0].text
        $toolText = $payload['result']['content'][0]['text'] ?? null;
        if (null !== $toolText) {
            return (string) $toolText;
        }
        // Resource response: result.contents[0].text
        $resourceText = $payload['result']['contents'][0]['text'] ?? null;
        if (null !== $resourceText) {
            return (string) $resourceText;
        }

        Assert::fail('MCP response carries no readable text content.');
    }
}
