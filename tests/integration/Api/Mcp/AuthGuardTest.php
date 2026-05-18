<?php

/*
 * AuthGuardTest.php
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
use Laravel\Passport\Passport;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\TestCase;

/**
 * Class AuthGuardTest
 *
 * Verifies that the MCP route requires Passport authentication plus the
 * `mcp:use` scope (WIP_MCP D-039; supersedes D-005).
 *
 * @internal
 *
 * @coversNothing
 */
final class AuthGuardTest extends TestCase
{
    use EnsuresPassportKeys;

    private const string MCP_URL = '/api/v1/mcp';

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testGivenAuthenticatedUserWithMcpScopeWhenInitializingThenReturnsOk(): void
    {
        FireflyConfig::set('allow_mcp', true);

        $user = $this->createAuthenticatedUser();
        Passport::actingAs($user, ['mcp:use']);

        $response = $this->postJson(self::MCP_URL, $this->initializePayload(), [
            'Accept' => 'application/json, text/event-stream'
        ]);

        $response->assertOk();
    }

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testGivenNoBearerWhenInitializingThenReturns401(): void
    {
        FireflyConfig::set('allow_mcp', true);

        $response = $this->postJson(self::MCP_URL, $this->initializePayload(), [
            'Accept' => 'application/json, text/event-stream'
        ]);

        $response->assertStatus(401);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }

    /**
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
