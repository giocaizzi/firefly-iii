<?php

/*
 * FireflyServer.php
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

namespace FireflyIII\Mcp\Servers;

use FireflyIII\Mcp\Resources\AccountTypesCatalogResource;
use FireflyIII\Mcp\Resources\CurrenciesCatalogResource;
use FireflyIII\Mcp\Resources\LinkTypesCatalogResource;
use FireflyIII\Mcp\Resources\TransactionTypesCatalogResource;
use Laravel\Mcp\Server;

/**
 * Single MCP server entrypoint for Firefly III (WIP_MCP D-008).
 *
 * The feature-flag gate lives in FireflyIII\Http\Middleware\McpFeatureFlag, chained on
 * the route in routes/ai.php alongside auth:api. Keeping the gate in middleware (rather
 * than overriding boot() here) means the request is rejected before any MCP framework
 * code runs, mirroring the webhook precedent.
 */
final class FireflyServer extends Server
{
    protected string $name = 'Firefly III MCP';

    protected string $version;

    // Populated by Sprint Teammates B (read) and C (write); see WIP_MCP §4.
    protected array $tools = [];

    // Populated by Sprint Teammate D; see WIP_MCP §5.
    protected array $resources = [
        CurrenciesCatalogResource::class,
        AccountTypesCatalogResource::class,
        TransactionTypesCatalogResource::class,
        LinkTypesCatalogResource::class
    ];

    protected string $instructions = <<<'MARKDOWN'
        Firefly III personal-finance MCP server. Tools and resources operate on the
        authenticated user's data, scoped via Passport Personal Access Tokens.
        MARKDOWN;

    public function __construct(\Laravel\Mcp\Server\Contracts\Transport $transport)
    {
        parent::__construct($transport);
        $this->version = (string) config('firefly.version');
    }
}
