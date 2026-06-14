<?php

/*
 * UserBudgetsResourceTest.php
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

use FireflyIII\Mcp\Resources\UserBudgetsResource;
use FireflyIII\Models\Budget;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\TestCase;

/**
 * Covers UserBudgetsResource — auth-scoped, flat {id, name, active}.
 *
 * @internal
 *
 * @coversNothing
 */
final class UserBudgetsResourceTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;

    /**
     * @covers \FireflyIII\Mcp\Resources\UserBudgetsResource
     */
    public function testGivenSeededBudgetWhenReadingUserBudgetsThenReturnsFlatBudgetRow(): void
    {
        $user = $this->createAuthenticatedUser();
        Budget::create([
            'user_id'       => $user->id,
            'user_group_id' => $user->user_group_id,
            'name'          => 'Monthly groceries',
            'active'        => true
        ]);

        $response = $this->invokeResource($user, UserBudgetsResource::class);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertCount(1, $payload['data']);
        $row = $payload['data'][0];
        self::assertArrayNotHasKey('attributes', $row);
        self::assertSame('Monthly groceries', $row['name']);
        self::assertTrue($row['active']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
