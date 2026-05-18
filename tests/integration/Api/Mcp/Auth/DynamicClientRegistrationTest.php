<?php

/*
 * DynamicClientRegistrationTest.php
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
 * Class DynamicClientRegistrationTest
 *
 * Verifies the RFC 7591 Dynamic Client Registration endpoint exposed at
 * POST /oauth/register by `Mcp::oauthRoutes()`. Confirms:
 *   - Registrations with a redirect host listed in `config('mcp.redirect_domains')`
 *     succeed and yield a public PKCE client (`token_endpoint_auth_method: none`).
 *   - Registrations with a non-allowlisted redirect host are rejected.
 *   - Missing `redirect_uris` is rejected.
 *
 * See WIP_MCP.md D-039 and D-040.
 *
 * @internal
 *
 * @coversNothing
 */
final class DynamicClientRegistrationTest extends TestCase
{
    use EnsuresPassportKeys;

    private const string REGISTER_URL = '/oauth/register';

    /**
     * @covers \Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController
     */
    public function testGivenAllowlistedRedirectThenRegistersPublicPkceClient(): void
    {
        $response = $this->postJson(self::REGISTER_URL, [
            'client_name'   => 'Test Client',
            'redirect_uris' => ['https://claude.ai/callback'],
        ]);

        // RFC 7591 §3.2.1 prescribes 201 Created on success. The laravel/mcp
        // controller returns 200 by default — accept either to stay robust against
        // upstream evolution while pinning the behavioural contract via the body.
        self::assertContains(
            $response->getStatusCode(),
            [200, 201],
            'DCR endpoint must return 200 or 201 on success.'
        );

        $response->assertJsonStructure([
            'client_id',
            'grant_types',
            'response_types',
            'redirect_uris',
            'scope',
            'token_endpoint_auth_method',
        ]);

        $data = $response->json();
        self::assertNotEmpty($data['client_id'], 'client_id must be present in the registration response.');
        self::assertSame(['https://claude.ai/callback'], $data['redirect_uris']);
        self::assertContains('authorization_code', $data['grant_types']);
        self::assertContains('refresh_token', $data['grant_types']);
        self::assertSame(['code'], $data['response_types']);
        self::assertSame('mcp:use', $data['scope']);
        self::assertSame('none', $data['token_endpoint_auth_method'], 'MCP clients must be issued as public PKCE clients.');
    }

    /**
     * @covers \Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController
     */
    public function testGivenNonAllowlistedRedirectHostThenRejectsRegistration(): void
    {
        $response = $this->postJson(self::REGISTER_URL, [
            'client_name'   => 'Evil Client',
            'redirect_uris' => ['https://evil.example.com/callback'],
        ]);

        // The controller returns 400 (RFC 7591 §3.2.2 invalid_redirect_uri); some
        // Laravel validation paths normalize that to 422. Both signal "rejected".
        self::assertContains(
            $response->getStatusCode(),
            [400, 422],
            'Non-allowlisted redirect_uris must be rejected by the DCR endpoint.'
        );
    }

    /**
     * @covers \Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController
     */
    public function testGivenEmptyRedirectUrisThenRejectsRegistration(): void
    {
        $response = $this->postJson(self::REGISTER_URL, [
            'client_name'   => 'No-Redirect Client',
            'redirect_uris' => [],
        ]);

        self::assertContains(
            $response->getStatusCode(),
            [400, 422],
            'Empty redirect_uris must be rejected by the DCR endpoint.'
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
