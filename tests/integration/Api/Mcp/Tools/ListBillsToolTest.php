<?php

/*
 * ListBillsToolTest.php
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

use FireflyIII\Mcp\Tools\ListBillsTool;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\TestCase;

/**
 * Covers WIP_MCP §4.3 ListBillsTool — empty-list happy path plus active filter.
 *
 * @internal
 *
 * @coversNothing
 */
final class ListBillsToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;

    /**
     * @covers \FireflyIII\Mcp\Tools\ListBillsTool
     */
    public function testGivenActiveFilterWhenListingBillsThenAcceptsArgument(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, ListBillsTool::class, ['active' => true]);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertSame([], $payload['data']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ListBillsTool
     */
    public function testGivenAuthenticatedUserWhenListingBillsThenReturnsReducedEnvelope(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, ListBillsTool::class, []);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertSame([], $payload['data']);
        self::assertArrayHasKey('pagination', $payload['meta']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
