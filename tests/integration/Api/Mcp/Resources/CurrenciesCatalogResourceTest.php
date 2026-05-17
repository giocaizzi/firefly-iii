<?php

/*
 * CurrenciesCatalogResourceTest.php
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

namespace Tests\integration\Api\Mcp\Resources;

use FireflyIII\Mcp\Resources\CurrenciesCatalogResource;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\TestCase;

/**
 * Covers WIP_MCP §5.1 CurrenciesCatalogResource — enabled currencies only, flat
 * {code, name, symbol, decimal_places} shape.
 *
 * @internal
 *
 * @coversNothing
 */
final class CurrenciesCatalogResourceTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;

    /**
     * @covers \FireflyIII\Mcp\Resources\CurrenciesCatalogResource
     */
    public function testGivenAuthenticatedUserWhenReadingCurrenciesCatalogThenReturnsReducedRows(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeResource($user, CurrenciesCatalogResource::class);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertArrayHasKey('data', $payload);
        self::assertNotEmpty($payload['data']);

        $first = $payload['data'][0];
        // The reducer drops `type` and flattens attributes onto the row.
        self::assertArrayNotHasKey('type', $first);
        self::assertArrayNotHasKey('attributes', $first);
        self::assertArrayHasKey('code', $first);
        self::assertArrayHasKey('name', $first);
        self::assertArrayHasKey('symbol', $first);
        self::assertArrayHasKey('decimal_places', $first);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
