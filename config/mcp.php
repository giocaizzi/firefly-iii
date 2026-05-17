<?php

/*
 * mcp.php
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

/*
 * Configuration for laravel/mcp.
 *
 * Published from vendor/laravel/mcp/config/mcp.php and tightened per
 * WIP_MCP.md D-040. Three knobs that matter:
 *
 *   - redirect_domains: which OAuth callback hosts the Dynamic Client
 *     Registration (RFC 7591) endpoint will accept. Default upstream is
 *     ['*'], which is an open-redirect / token-exfiltration vector. We
 *     pin to claude.ai (covers web, Desktop, mobile) and loopback (covers
 *     Claude Code's local Anthropic SDK).
 *   - custom_schemes: private-use URI schemes (RFC 8252) for native
 *     desktop clients like Cursor or VS Code. Empty by default — re-add
 *     when a real need surfaces.
 *   - authorization_server: issuer URL advertised in RFC 8414 metadata.
 *     Pinned to APP_URL so the metadata reflects the canonical public
 *     hostname even when Laravel is reached via internal hostnames.
 */

return [
    'redirect_domains' => [
        'https://claude.ai',
        'http://localhost',
        'http://127.0.0.1',
    ],

    'custom_schemes' => [],

    'authorization_server' => env('APP_URL'),
];
