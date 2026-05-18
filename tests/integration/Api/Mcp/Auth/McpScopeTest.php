<?php

/*
 * McpScopeTest.php
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

namespace Tests\integration\Api\Mcp\Auth;

use FireflyIII\Support\Facades\FireflyConfig;
use Laravel\Passport\Passport;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\TestCase;

/**
 * Class McpScopeTest
 *
 * Verifies the auth chain on `/api/v1/mcp`:
 *   auth:api → CheckToken::using('mcp:use') → McpFeatureFlag.
 *
 * Each step has a distinct failure mode and HTTP status — 401, 403, 404 respectively.
 * See WIP_MCP.md D-039.
 *
 * @internal
 *
 * @coversNothing
 */
final class McpScopeTest extends TestCase
{
    use EnsuresPassportKeys;

    private const string MCP_URL = '/api/v1/mcp';

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testGivenNoAuthorizationHeaderThenReturns401WithWwwAuthenticateChallenge(): void
    {
        FireflyConfig::set('allow_mcp', true);

        $response = $this->postJson(self::MCP_URL, $this->initializePayload(), [
            'Accept' => 'application/json, text/event-stream',
        ]);

        $response->assertStatus(401);

        // RFC 9728 §5.1 challenge: AddWwwAuthenticateHeader middleware injects
        // a Bearer challenge whose realm is "mcp" and whose resource_metadata
        // points at the protected-resource discovery document.
        $challenge = $response->headers->get('WWW-Authenticate');
        self::assertIsString($challenge, 'A 401 from /api/v1/mcp must carry a WWW-Authenticate header.');
        self::assertStringContainsString('Bearer', $challenge);
        self::assertStringContainsString('realm="mcp"', $challenge);
        self::assertStringContainsString('resource_metadata="', $challenge);
    }

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testGivenTokenWithoutMcpUseScopeThenRequestIsRejected(): void
    {
        FireflyConfig::set('allow_mcp', true);

        $user = $this->createAuthenticatedUser();
        // Mint a Passport access token with NO mcp:use scope — Passport's CheckToken
        // middleware raises MissingScopeException (extends AuthorizationException).
        // Firefly's app/Exceptions/Handler.php remaps AuthorizationException to 401
        // (not Laravel's stock 403), so on this codebase the rejection surfaces as
        // 401. We accept either to remain semantically correct if the handler is
        // later relaxed.
        Passport::actingAs($user, []);

        $response = $this->postJson(self::MCP_URL, $this->initializePayload(), [
            'Accept' => 'application/json, text/event-stream',
        ]);

        self::assertContains(
            $response->getStatusCode(),
            [401, 403],
            'A token without the mcp:use scope must be rejected by CheckToken.'
        );
    }

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testGivenTokenWithMcpUseScopeThenInitializeReturnsJsonRpcResponse(): void
    {
        FireflyConfig::set('allow_mcp', true);

        $user = $this->createAuthenticatedUser();
        Passport::actingAs($user, ['mcp:use']);

        $response = $this->postJson(self::MCP_URL, $this->initializePayload(), [
            'Accept' => 'application/json, text/event-stream',
        ]);

        $response->assertOk();
        // The HTTP transport may emit either application/json or text/event-stream.
        // Either is acceptable; we only assert that the body is non-empty and that
        // the JSON-RPC `id` round-trips when the body is decodable as JSON.
        $body = $response->getContent();
        self::assertIsString($body);
        self::assertNotSame('', $body, 'Initialize must return a non-empty JSON-RPC response.');

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            self::assertSame('2.0', $decoded['jsonrpc'] ?? null, 'JSON-RPC envelope must echo jsonrpc=2.0.');
            self::assertSame(1, $decoded['id'] ?? null, 'JSON-RPC envelope must echo the request id.');
        }
    }

    /**
     * @covers \FireflyIII\Http\Middleware\McpFeatureFlag
     */
    public function testGivenFeatureFlagOffThenScopedRequestStillReturns404(): void
    {
        // The middleware chain is auth:api → CheckToken → McpFeatureFlag, so a fully
        // authorised request (valid token + mcp:use scope) still gets 404 when the
        // feature flag is off. This proves the kill switch wins over auth.
        FireflyConfig::set('allow_mcp', false);

        $user = $this->createAuthenticatedUser();
        Passport::actingAs($user, ['mcp:use']);

        $response = $this->postJson(self::MCP_URL, $this->initializePayload(), [
            'Accept' => 'application/json, text/event-stream',
        ]);

        $response->assertStatus(404);
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
                'clientInfo'      => ['name' => 'phpunit', 'version' => '0.0.0'],
            ],
        ];
    }
}
