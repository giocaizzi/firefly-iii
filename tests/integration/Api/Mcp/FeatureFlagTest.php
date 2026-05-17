<?php

/*
 * FeatureFlagTest.php
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

namespace Tests\integration\Api\Mcp;

use FireflyIII\Support\Facades\FireflyConfig;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\TestCase;

/**
 * Class FeatureFlagTest
 *
 * Verifies the allow_mcp feature-flag gate (WIP_MCP D-007, McpFeatureFlag middleware).
 *
 * @internal
 *
 * @coversNothing
 */
final class FeatureFlagTest extends TestCase
{
    use EnsuresPassportKeys;

    private const string MCP_URL = '/api/v1/mcp';

    /**
     * @covers \FireflyIII\Http\Middleware\McpFeatureFlag
     */
    public function testGivenFeatureFlagDisabledWhenInitializingThenReturns404(): void
    {
        FireflyConfig::set('allow_mcp', false);

        $user = $this->createAuthenticatedUser();
        $this->actingAs($user, 'api');

        $response = $this->postJson(self::MCP_URL, $this->initializePayload(), [
            'Accept' => 'application/json, text/event-stream'
        ]);

        $response->assertStatus(404);
    }

    /**
     * @covers \FireflyIII\Http\Middleware\McpFeatureFlag
     */
    public function testGivenFeatureFlagEnabledWhenInitializingThenReturnsSuccessfulHandshake(): void
    {
        FireflyConfig::set('allow_mcp', true);

        $user = $this->createAuthenticatedUser();
        $this->actingAs($user, 'api');

        $response = $this->postJson(self::MCP_URL, $this->initializePayload(), [
            'Accept' => 'application/json, text/event-stream'
        ]);

        $response->assertOk();
        // initialize response includes the server name & protocol version
        $response->assertJsonPath('result.serverInfo.name', 'Firefly III MCP');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }

    /**
     * Minimal JSON-RPC initialize payload per MCP spec 2025-06-18.
     *
     * @return array<string, mixed>
     */
    private function initializePayload(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [
                'protocolVersion' => '2025-06-18',
                'capabilities'    => [],
                'clientInfo'      => ['name' => 'phpunit', 'version' => '0.0.0']
            ]
        ];
    }
}
