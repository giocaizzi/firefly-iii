<?php

/*
 * AccountTypesCatalogResourceTest.php
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

use FireflyIII\Mcp\Resources\AccountTypesCatalogResource;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\TestCase;

/**
 * Covers AccountTypesCatalogResource — flat {type} rows (no `role` column).
 *
 * @internal
 *
 * @coversNothing
 */
final class AccountTypesCatalogResourceTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;

    /**
     * @covers \FireflyIII\Mcp\Resources\AccountTypesCatalogResource
     */
    public function testGivenAuthenticatedUserWhenReadingAccountTypesCatalogThenReturnsReducedRows(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeResource($user, AccountTypesCatalogResource::class);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertNotEmpty($payload['data']);
        $first = $payload['data'][0];
        // `attributes` wrapper stripped; `type` (the AccountType label, NOT the JSON:API marker)
        // is hoisted onto the row by the reducer.
        self::assertArrayNotHasKey('attributes', $first);
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('type', $first);
        self::assertIsString($first['type']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
