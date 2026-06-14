<?php

/*
 * ListTransactionsToolTest.php
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
use FireflyIII\Mcp\Tools\ListTransactionsTool;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers ListTransactionsTool. Verifies the reduced envelope returned for a
 * seeded withdrawal plus the type-filter argument shape.
 *
 * @internal
 *
 * @coversNothing
 */
final class ListTransactionsToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\ListTransactionsTool
     */
    public function testGivenSeededWithdrawalWhenListingTransactionsThenReturnsItemInEnvelope(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $destination = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Groceries');
        $this->createWithdrawal($user, $source, $destination, '12.50', 'Lunch');

        $response = $this->invokeTool($user, ListTransactionsTool::class, []);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertCount(1, $payload['data']);
        self::assertArrayHasKey('id', $payload['data'][0]);
        self::assertArrayHasKey('transactions', $payload['data'][0]);
        self::assertSame('Lunch', $payload['data'][0]['transactions'][0]['description']);
        self::assertSame('12.500000000000', $payload['data'][0]['transactions'][0]['amount']);
        self::assertSame(1, $payload['meta']['pagination']['current_page']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ListTransactionsTool
     */
    public function testGivenTypeFilterWhenListingTransactionsThenAcceptsArgument(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, ListTransactionsTool::class, ['type' => 'deposit', 'limit' => 10]);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertSame(10, $payload['meta']['pagination']['per_page']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
