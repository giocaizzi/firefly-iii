<?php

/*
 * ListAccountsToolTest.php
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

namespace Tests\integration\Api\Mcp\Tools;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Mcp\Tools\ListAccountsTool;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers ListAccountsTool. Asserts the reduced JSON:API envelope shape and the
 * type-filter behaviour for a seeded asset account.
 *
 * @internal
 *
 * @coversNothing
 */
final class ListAccountsToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\ListAccountsTool
     */
    public function testGivenAccountsWhenListingThenReturnsCompactRowsFilteredByName(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->createAccount($user, AccountTypeEnum::ASSET, 'Main checking');
        $this->createAccount($user, AccountTypeEnum::ASSET, 'Savings');

        $payload = $this->decodeJson($this->invokeTool($user, ListAccountsTool::class, ['query' => 'CHECK']));

        self::assertCount(1, $payload['data']);
        $row = $payload['data'][0];
        self::assertSame('Main checking', $row['name']);
        self::assertSame(['id', 'name', 'type', 'currency', 'current_balance', 'active'], array_keys($row));
        self::assertSame('0', $row['current_balance']);

        $full = $this->decodeJson($this->invokeTool($user, ListAccountsTool::class, ['query' => 'check', 'compact' => false]));
        self::assertArrayHasKey('currency_symbol', $full['data'][0]);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ListAccountsTool
     */
    public function testGivenLimitFilterWhenListingAccountsThenPaginationReflectsArgument(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, ListAccountsTool::class, ['type' => 'asset', 'limit' => 5, 'active' => true]);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertSame(5, $payload['meta']['pagination']['per_page']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ListAccountsTool
     */
    public function testGivenSeededAssetAccountWhenListingAccountsThenReturnsAccountInEnvelope(): void
    {
        $user    = $this->createAuthenticatedUser();
        $account = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');

        $response = $this->invokeTool($user, ListAccountsTool::class, ['type' => 'asset']);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertCount(1, $payload['data']);
        self::assertSame((int) $account->id, $payload['data'][0]['id']);
        self::assertSame('Checking', $payload['data'][0]['name']);
        self::assertSame('asset', $payload['data'][0]['type']);
        // default page size is the MCP default (25), not the user's listPageSize preference
        self::assertSame(25, $payload['meta']['pagination']['per_page']);
        self::assertSame(1, $payload['meta']['pagination']['current_page']);
        self::assertArrayNotHasKey('included', $payload);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
