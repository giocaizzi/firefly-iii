<?php

/*
 * TransactionTypesCatalogResourceTest.php
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

use FireflyIII\Mcp\Resources\TransactionTypesCatalogResource;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\TestCase;

/**
 * Covers TransactionTypesCatalogResource — flat {type} rows.
 *
 * @internal
 *
 * @coversNothing
 */
final class TransactionTypesCatalogResourceTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;

    /**
     * @covers \FireflyIII\Mcp\Resources\TransactionTypesCatalogResource
     */
    public function testGivenAuthenticatedUserWhenReadingTransactionTypesCatalogThenReturnsReducedRows(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeResource($user, TransactionTypesCatalogResource::class);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertNotEmpty($payload['data']);
        $first = $payload['data'][0];
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
