<?php

/*
 * CallsMcpEndpoint.php
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

use FireflyIII\Support\Facades\FireflyConfig;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Drives the MCP server over its real HTTP mount (`/api/v1/mcp`) rather than the
 * in-process laravel/mcp harness, so the route, the `auth:api` + feature-flag
 * middleware, JSON-RPC dispatch and envelope serialisation are all exercised.
 */
trait CallsMcpEndpoint
{
    private const string MCP_ENDPOINT = '/api/v1/mcp';

    /**
     * Flip the runtime feature flag the McpFeatureFlag middleware gates on.
     */
    protected function enableMcp(): void
    {
        FireflyConfig::set('allow_mcp', true);
    }

    /**
     * POST a single JSON-RPC request to the MCP endpoint. Empty params are encoded as a
     * JSON object (`{}`) rather than an array so the server's schema coercion sees the
     * shape it expects.
     *
     * @param array<string, mixed> $params
     */
    protected function mcpCall(string $method, array $params = []): TestResponse
    {
        return $this->postJson(self::MCP_ENDPOINT, [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => $method,
            'params'  => [] === $params ? new \stdClass() : $params,
        ], ['Accept' => 'application/json, text/event-stream']);
    }

    /**
     * Invoke a tool by its registered name and return the decoded JSON-RPC body.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    protected function callTool(string $name, array $arguments = []): array
    {
        return $this->mcpCall('tools/call', [
            'name'      => $name,
            'arguments' => [] === $arguments ? new \stdClass() : $arguments,
        ])->assertOk()->json();
    }

    /**
     * Decode the `result.content[0].text` JSON document a tool emits.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    protected function decodeToolPayload(array $body): array
    {
        $text = $body['result']['content'][0]['text'] ?? null;
        Assert::assertIsString($text, 'Tool response carries no text content.');

        /** @var array<string, mixed> */
        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }
}
