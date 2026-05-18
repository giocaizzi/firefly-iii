<?php

/*
 * ai.php
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

use FireflyIII\Http\Middleware\McpFeatureFlag;
use FireflyIII\Mcp\Servers\FireflyServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

// MCP-spec OAuth discovery (WIP_MCP.md D-039). Registers:
//   GET /.well-known/oauth-protected-resource             (RFC 9728)
//   GET /.well-known/oauth-authorization-server           (RFC 8414)
//   POST /oauth/register                                  (RFC 7591 — Dynamic Client Registration)
// Auto-injects the `mcp:use` scope into Passport via Mcp::ensureMcpScope().
Mcp::oauthRoutes();

// MCP server mount. The path is literal (Laravel\Mcp\Server\Registrar::web()
// registers it as-is; routes/ai.php is loaded outside the api/ group, so the
// final URL is exactly /api/v1/mcp). See WIP_MCP.md D-006.
//
// Middleware order (top-down):
//   1. McpFeatureFlag — if `allow_mcp` is off, return 404. The route simply
//      doesn't exist for that instance, so clients shouldn't try to OAuth in.
//   2. auth:api — Passport bearer auth; missing/invalid token returns 401 +
//      WWW-Authenticate (set automatically by laravel/mcp's middleware), which
//      is what kicks MCP clients into the OAuth discovery flow.
//   3. CheckToken (scope=mcp:use) — token is valid but lacks the MCP scope.
Mcp::web('/api/v1/mcp', FireflyServer::class)->middleware([
    McpFeatureFlag::class,
    'auth:api',
    CheckToken::using('mcp:use'),
]);
