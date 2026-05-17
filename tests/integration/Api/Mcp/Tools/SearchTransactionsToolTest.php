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

use FireflyIII\Mcp\Tools\SearchTransactionsTool;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\TestCase;

/**
 * Covers WIP_MCP §4.2 SearchTransactionsTool. The empty-query rejection guards the
 * required-arg contract; the empty-result happy path verifies the reduced envelope
 * is preserved when no journals match.
 *
 * @internal
 *
 * @coversNothing
 */
final class SearchTransactionsToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;

    /**
     * @covers \FireflyIII\Mcp\Tools\SearchTransactionsTool
     */
    public function testGivenAuthenticatedUserWhenSearchingThenReturnsReducedEnvelope(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, SearchTransactionsTool::class, ['query' => 'lunch']);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertSame([], $payload['data']);
        self::assertArrayHasKey('pagination', $payload['meta']);
    }

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
