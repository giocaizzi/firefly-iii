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
 * Pins the `?toolset=` split: `everyday` (the default) serves Toolset::EVERYDAY, `admin` serves
 * every other registered tool, and anything else is a 400.
 *
 * @internal
 *
 * @coversNothing
 */
final class ToolsetTest extends TestCase
{
    use CallsMcpEndpoint;
    use EnsuresPassportKeys;

    private const string ADMIN_TOOL    = 'delete-rule-tool';
    private const string EVERYDAY_TOOL = 'health-tool';

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
     * @covers \FireflyIII\Mcp\Servers\Toolset
     */
    public function testAdminToolsetListsOnlyAdminTools(): void
    {
        $this->actingAsUser();
        $sets     = $this->toolsByToolset();
        $everyday = array_column($sets['everyday'], 'name');
        $admin    = array_column($sets['admin'], 'name');

        self::assertCount(24, $admin);
        self::assertContains(self::ADMIN_TOOL, $admin);
        self::assertSame([], array_intersect($everyday, $admin), 'The everyday and admin toolsets overlap.');
        self::assertCount(61, array_unique([...$everyday, ...$admin]), 'The two toolsets do not cover every registered tool.');
    }

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testToolCallOutsideTheToolsetNamesTheOtherOne(): void
    {
        $this->actingAsUser();
        $admin    = ['name' => self::ADMIN_TOOL, 'arguments' => new \stdClass()];
        $everyday = ['name' => self::EVERYDAY_TOOL, 'arguments' => new \stdClass()];

        $body     = $this->mcpCall('tools/call', $admin)->assertOk()->json();
        self::assertSame(-32602, $body['error']['code'] ?? null);
        self::assertStringContainsString('toolset=admin', $body['error']['message'] ?? '');

        $body     = $this->mcpCall('tools/call', $everyday, 'admin')->assertOk()->json();
        self::assertSame(-32602, $body['error']['code'] ?? null);
        self::assertStringContainsString('everyday toolset', $body['error']['message'] ?? '');

        $body     = $this->mcpCall('tools/call', $admin, 'admin')->assertOk()->json();
        self::assertArrayNotHasKey('error', $body, 'The admin tool is not dispatchable under toolset=admin.');
    }

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testUnknownToolsetIsRejected(): void
    {
        $this->actingAsUser();

        foreach (['all', 'nope'] as $toolset) {
            $response = $this->mcpCall('tools/list', [], $toolset);
            $response->assertStatus(400);
            self::assertStringContainsString('toolset=everyday', (string) $response->json('message'));
            self::assertStringContainsString('toolset=admin', (string) $response->json('message'));
        }
    }

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testResourcesAreTheSameInEveryToolset(): void
    {
        $this->actingAsUser();

        self::assertSame(
            $this->mcpCall('resources/list', [], 'admin')->assertOk()->json('result'),
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
        $response = $this->mcpCall('initialize', $this->initializeParams(), 'admin');
        $response->assertStatus(401);
        self::assertStringContainsString('/.well-known/oauth-protected-resource/api/v1/mcp"', (string) $response->headers->get('WWW-Authenticate'));

        Passport::actingAs($this->createAuthenticatedUser(), ['mcp:use']);
        $this->mcpCall('initialize', $this->initializeParams(), 'admin')->assertOk();
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
