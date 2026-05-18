<?php

/*
 * OAuthDiscoveryTest.php
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

use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\TestCase;

/**
 * Class OAuthDiscoveryTest
 *
 * Verifies the OAuth well-known discovery documents emitted by `Mcp::oauthRoutes()`
 * conform to RFC 9728 (protected-resource metadata) and RFC 8414 (authorization-server
 * metadata). Both endpoints must be publicly reachable per spec — no auth header,
 * no feature flag, no scope. See WIP_MCP.md D-039.
 *
 * @internal
 *
 * @coversNothing
 */
final class OAuthDiscoveryTest extends TestCase
{
    use EnsuresPassportKeys;

    /**
     * @covers \Laravel\Mcp\Server\Registrar
     */
    public function testProtectedResourceMetadataIsPublicAndRfc9728Compliant(): void
    {
        $response = $this->getJson('/.well-known/oauth-protected-resource');

        $response->assertOk();
        $response->assertJsonStructure([
            'resource',
            'authorization_servers',
            'scopes_supported',
        ]);

        $data = $response->json();
        self::assertIsArray($data['authorization_servers'], 'authorization_servers must be a JSON array.');
        self::assertNotEmpty($data['authorization_servers'], 'authorization_servers must contain at least one issuer.');
        self::assertContains('mcp:use', $data['scopes_supported'], 'scopes_supported must advertise the mcp:use scope.');
    }

    /**
     * @covers \Laravel\Mcp\Server\Registrar
     */
    public function testAuthorizationServerMetadataIsPublicAndRfc8414Compliant(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk();
        $response->assertJsonStructure([
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'registration_endpoint',
            'response_types_supported',
            'code_challenge_methods_supported',
            'scopes_supported',
            'grant_types_supported',
        ]);

        $data = $response->json();
        self::assertContains('code', $data['response_types_supported'], 'response_types_supported must include "code" for OAuth 2.1 / MCP-spec compliance.');
        self::assertContains('S256', $data['code_challenge_methods_supported'], 'code_challenge_methods_supported must include "S256" (PKCE).');
        self::assertContains('mcp:use', $data['scopes_supported'], 'scopes_supported must advertise the mcp:use scope.');
        self::assertContains('authorization_code', $data['grant_types_supported'], 'grant_types_supported must include authorization_code.');
        self::assertContains('refresh_token', $data['grant_types_supported'], 'grant_types_supported must include refresh_token.');
    }

    /**
     * @covers \Laravel\Mcp\Server\Registrar
     */
    public function testDiscoveryEndpointsRequireNoAuthorizationHeader(): void
    {
        // Explicitly issue both requests with NO Authorization header — RFC 9728 §3
        // and RFC 8414 §3 both require these documents to be publicly retrievable.
        $protectedResource   = $this->withHeaders([])->getJson('/.well-known/oauth-protected-resource');
        $authorizationServer = $this->withHeaders([])->getJson('/.well-known/oauth-authorization-server');

        $protectedResource->assertOk();
        $authorizationServer->assertOk();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
