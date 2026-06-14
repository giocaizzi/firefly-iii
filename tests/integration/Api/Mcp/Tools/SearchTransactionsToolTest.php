<?php

/*
 * SearchTransactionsToolTest.php
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
use FireflyIII\Mcp\Tools\SearchTransactionsTool;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers SearchTransactionsTool. Empty-query rejection guards the required-arg
 * contract; the seeded happy path verifies the search hits a matching withdrawal.
 *
 * @internal
 *
 * @coversNothing
 */
final class SearchTransactionsToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\SearchTransactionsTool
     */
    public function testGivenDateFiltersWhenSearchingThenAcceptsArgs(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, SearchTransactionsTool::class, [
            'query' => 'groceries',
            'start' => '2026-01-01',
            'end'   => '2026-12-31',
            'limit' => 5
        ]);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertSame(5, $payload['meta']['pagination']['per_page']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\SearchTransactionsTool
     */
    public function testGivenEmptyQueryWhenSearchingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, SearchTransactionsTool::class, ['query' => '']);
        $response->assertHasErrors(['Search query is required']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\SearchTransactionsTool
     */
    public function testGivenSeededWithdrawalWhenSearchingByDescriptionThenReturnsMatch(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $destination = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Supermarket');
        $this->createWithdrawal($user, $source, $destination, '42.00', 'groceries');

        $response = $this->invokeTool($user, SearchTransactionsTool::class, ['query' => 'groceries']);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertCount(1, $payload['data']);
        self::assertSame('groceries', $payload['data'][0]['transactions'][0]['description']);
        self::assertArrayHasKey('pagination', $payload['meta']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
