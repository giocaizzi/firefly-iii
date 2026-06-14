<?php

/*
 * GetBudgetToolTest.php
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

use FireflyIII\Mcp\Tools\GetBudgetTool;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers GetBudgetTool happy path with a seeded budget plus the not-found
 * error path.
 *
 * @internal
 *
 * @coversNothing
 */
final class GetBudgetToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\GetBudgetTool
     */
    public function testGivenExistingBudgetWhenGettingThenReturnsFlatEnvelope(): void
    {
        $user   = $this->createAuthenticatedUser();
        $budget = $this->createBudget($user, 'Daily');

        $response = $this->invokeTool($user, GetBudgetTool::class, ['id' => $budget->id]);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertSame((int) $budget->id, $payload['data']['id']);
        self::assertSame('Daily', $payload['data']['name']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\GetBudgetTool
     */
    public function testGivenMissingBudgetWhenGettingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, GetBudgetTool::class, ['id' => 999_999]);
        $response->assertHasErrors(['Budget not found']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
