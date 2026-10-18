<?php

/*
 * BudgetsToolsTest.php
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

use FireflyIII\Mcp\Tools\BudgetsReadTool;
use FireflyIII\Mcp\Tools\BudgetsWriteTool;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use Tests\integration\Api\Mcp\Concerns\CallsApiTools;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class BudgetsToolsTest extends TestCase
{
    use CallsApiTools;
    use EnsuresPassportKeys;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\BudgetsReadTool
     * @covers \FireflyIII\Mcp\Tools\BudgetsWriteTool
     */
    public function testBudgetLimitRoundTripAndDeleteNeedsConfirmation(): void
    {
        $user   = $this->createAuthenticatedUser();
        $budget = $this->createBudget($user, 'Fun');
        $range  = ['start' => '2026-01-01', 'end' => '2026-01-31'];

        $limit = $this->okJson($user, BudgetsWriteTool::class, [
            'action'    => 'create',
            'kind'      => 'limit',
            'budget_id' => $budget->id,
            'data'      => [...$range, 'amount' => '150']
        ]);
        $id    = $limit['data']['id'];
        self::assertSame('150', $limit['data']['amount']);

        $list = $this->okJson($user, BudgetsReadTool::class, ['action' => 'list', 'kind' => 'limit', ...$range]);
        self::assertSame([$id], array_column($list['data'], 'id'));
        $get = $this->okJson($user, BudgetsReadTool::class, ['action' => 'get', 'kind' => 'limit', 'budget_id' => $budget->id, 'id' => $id]);
        self::assertSame($budget->id, (int) $get['data']['budget_id']);

        $updated = $this->okJson($user, BudgetsWriteTool::class, [
            'action'    => 'update',
            'kind'      => 'limit',
            'budget_id' => $budget->id,
            'id'        => $id,
            'data'      => ['amount' => '200']
        ]);
        self::assertSame('200', $updated['data']['amount']);

        $error = $this->invalidArgument($user, BudgetsWriteTool::class, ['action' => 'delete', 'id' => $budget->id], 'confirm');
        self::assertSame(['budget limits' => 1], $error['dependants']);
        self::assertTrue(Budget::whereKey($budget->id)->exists());

        $this->invokeTool($user, BudgetsWriteTool::class, [
            'action'    => 'delete',
            'kind'      => 'limit',
            'budget_id' => $budget->id,
            'id'        => $id
        ])->assertHasNoErrors();
        self::assertFalse(BudgetLimit::whereKey($id)->exists());
        $this->invokeTool($user, BudgetsWriteTool::class, ['action' => 'delete', 'id' => $budget->id])->assertHasNoErrors();
    }

    /**
     * @covers \FireflyIII\Mcp\Support\ApiBridge
     * @covers \FireflyIII\Mcp\Tools\BudgetsReadTool
     * @covers \FireflyIII\Mcp\Tools\BudgetsWriteTool
     */
    public function testBudgetRoundTrip(): void
    {
        $user    = $this->createAuthenticatedUser();
        $created = $this->okJson($user, BudgetsWriteTool::class, ['action' => 'create', 'data' => ['name' => 'Groceries', 'notes' => 'weekly']]);
        $id      = $created['data']['id'];
        self::assertSame('Groceries', $created['data']['name']);
        self::assertTrue(Budget::whereKey($id)->where('user_id', $user->id)->exists());

        $read = $this->okJson($user, BudgetsReadTool::class, ['action' => 'get', 'id' => $id]);
        self::assertSame(['id' => $id, 'name' => 'Groceries', 'active' => true], array_intersect_key($read['data'], ['id' => 0, 'name' => 0, 'active' => 0]));

        $list = $this->okJson($user, BudgetsReadTool::class, ['action' => 'list', 'fields' => ['id', 'notes']]);
        self::assertSame([['id' => $id, 'notes' => 'weekly']], $list['data']);
        self::assertFalse($list['meta']['has_more']);

        $updated = $this->okJson($user, BudgetsWriteTool::class, ['action' => 'update', 'id' => $id, 'data' => ['name' => 'Food']]);
        self::assertSame('Food', $updated['data']['name']);

        $this->invokeTool($user, BudgetsWriteTool::class, ['action' => 'delete', 'id' => $id])->assertHasNoErrors();
        self::assertFalse(Budget::whereKey($id)->exists());
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\AbstractApiTool
     */
    public function testValidationAndOwnership(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->createBudget($user, 'Taken');

        $this->invalidArgument($user, BudgetsWriteTool::class, ['action' => 'create', 'data' => ['name' => 'Taken']], 'data.name');
        $this->invalidArgument($user, BudgetsWriteTool::class, ['action' => 'create', 'data' => ['notes' => 'x']], 'data.name');
        $this->invalidArgument($user, BudgetsWriteTool::class, ['data' => ['name' => 'x']], 'action');
        $this->invalidArgument($user, BudgetsReadTool::class, ['action' => 'get'], 'id');

        $other  = $this->createOtherUser();
        $hidden = $this->createBudget($other, 'Not yours');
        $this->invokeTool($user, BudgetsReadTool::class, ['action' => 'get', 'id' => $hidden->id])->assertHasErrors(['404']);
        $this->invokeTool($user, BudgetsWriteTool::class, ['action' => 'delete', 'id' => $hidden->id, 'confirm' => true])->assertHasErrors(['404']);
        self::assertTrue(Budget::whereKey($hidden->id)->exists());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
