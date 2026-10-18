<?php

/*
 * ToolsetTest.php
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

use FireflyIII\Mcp\Servers\Toolset;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\integration\Api\Mcp\Concerns\CallsMcpEndpoint;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\TestCase;

/**
 * Pins the `?toolset=` split: `everyday` (the default) serves Toolset::EVERYDAY, `all` serves
 * every registered tool, and anything else is a 400.
 *
 * @internal
 *
 * @coversNothing
 */
final class ToolsetTest extends TestCase
{
    use CallsMcpEndpoint;
    use EnsuresPassportKeys;

    private const string ADMIN_TOOL = 'delete-rule-tool';

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     * @covers \FireflyIII\Mcp\Servers\Toolset
     */
    public function testDefaultToolsetListsOnlyEverydayTools(): void
    {
        $this->actingAsUser();
        $expected = array_map(static fn (string $tool): string => Str::kebab(class_basename($tool)), Toolset::EVERYDAY);
        sort($expected);

        foreach ([null, 'everyday'] as $toolset) {
            $body  = $this->mcpCall('tools/list', [], $toolset)->assertOk()->json();
            $names = array_column($body['result']['tools'] ?? [], 'name');
            sort($names);

            self::assertSame($expected, $names, sprintf('toolset=%s served the wrong tools.', $toolset ?? '(none)'));
            self::assertNotContains(self::ADMIN_TOOL, $names);
            self::assertArrayNotHasKey('nextCursor', $body['result'], 'tools/list paginated the everyday set.');
        }
    }

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testAllToolsetListsEveryTool(): void
    {
        $this->actingAsUser();
        $body  = $this->mcpCall('tools/list', [], 'all')->assertOk()->json();
        $names = array_column($body['result']['tools'] ?? [], 'name');

        self::assertCount(63, $names);
        self::assertContains(self::ADMIN_TOOL, $names);
        self::assertArrayNotHasKey('nextCursor', $body['result'], 'tools/list paginated the full set.');
    }

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testAdminToolCallUnderEverydayIsRejected(): void
    {
        $this->actingAsUser();
        $params = ['name' => self::ADMIN_TOOL, 'arguments' => new \stdClass()];

        $body   = $this->mcpCall('tools/call', $params)->assertOk()->json();
        self::assertSame(-32602, $body['error']['code'] ?? null);
        self::assertStringContainsString('toolset=all', $body['error']['message'] ?? '');

        $body   = $this->mcpCall('tools/call', $params, 'all')->assertOk()->json();
        self::assertArrayNotHasKey('error', $body, 'The admin tool is not dispatchable under toolset=all.');
    }

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testUnknownToolsetIsRejected(): void
    {
        $this->actingAsUser();

        $response = $this->mcpCall('tools/list', [], 'admin');
        $response->assertStatus(400);
        self::assertStringContainsString('toolset=all', (string) $response->json('message'));
    }

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testResourcesAreTheSameInEveryToolset(): void
    {
        $this->actingAsUser();

        self::assertSame(
            $this->mcpCall('resources/list', [], 'all')->assertOk()->json('result'),
            $this->mcpCall('resources/list')->assertOk()->json('result')
        );
    }

    /**
     * The query string must not break the OAuth handshake: an unauthenticated call still gets
     * the 401 that points at the path's protected-resource metadata, and a bearer token works.
     *
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testAuthWorksWithToolsetQuery(): void
    {
        $response = $this->mcpCall('initialize', $this->initializeParams(), 'all');
        $response->assertStatus(401);
        self::assertStringContainsString('/.well-known/oauth-protected-resource/api/v1/mcp"', (string) $response->headers->get('WWW-Authenticate'));

        Passport::actingAs($this->createAuthenticatedUser(), ['mcp:use']);
        $this->mcpCall('initialize', $this->initializeParams(), 'all')->assertOk();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
        $this->enableMcp();
    }

    private function actingAsUser(): void
    {
        $this->actingAs($this->createAuthenticatedUser(), 'api');
    }

    /**
     * @return array<string, mixed>
     */
    private function initializeParams(): array
    {
        return [
            'protocolVersion' => '2025-06-18',
            'capabilities'    => new \stdClass(),
            'clientInfo'      => ['name' => 'phpunit', 'version' => '0.0.0'],
        ];
    }
}
