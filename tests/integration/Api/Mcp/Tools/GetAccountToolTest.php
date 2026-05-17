<?php

/*
 * GetAccountToolTest.php
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

use FireflyIII\Mcp\Tools\GetAccountTool;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\TestCase;

/**
 * Covers WIP_MCP §4.1 GetAccountTool. The happy path requires AccountEnrichment to run
 * inside the tool (currently absent — see Sprint-E queued observation A1), so the test
 * exercises the not-found error path which goes through the same auth + repository scope.
 *
 * @internal
 *
 * @coversNothing
 */
final class GetAccountToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;

    /**
     * @covers \FireflyIII\Mcp\Tools\GetAccountTool
     */
    public function testGivenMissingAccountWhenGettingByIdThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, GetAccountTool::class, ['id' => 999_999]);
        $response->assertHasErrors(['Account not found']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\GetAccountTool
     */
    public function testGivenMissingAccountWhenGettingByNameThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, GetAccountTool::class, ['name' => 'No-such-account']);
        $response->assertHasErrors(['Account not found']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
