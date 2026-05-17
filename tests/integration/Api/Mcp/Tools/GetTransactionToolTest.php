<?php

/*
 * GetTransactionToolTest.php
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

use FireflyIII\Mcp\Tools\GetTransactionTool;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\TestCase;

/**
 * Covers WIP_MCP §4.1 GetTransactionTool. The happy path requires TransactionGroupEnrichment
 * inside the tool (queued observation A2), so this test exercises the not-found error path
 * which still verifies the user-scoped repository lookup.
 *
 * @internal
 *
 * @coversNothing
 */
final class GetTransactionToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;

    /**
     * @covers \FireflyIII\Mcp\Tools\GetTransactionTool
     */
    public function testGivenMissingTransactionWhenGettingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, GetTransactionTool::class, ['id' => 999_999]);
        $response->assertHasErrors(['Transaction not found']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
