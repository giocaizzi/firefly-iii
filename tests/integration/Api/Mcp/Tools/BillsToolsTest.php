<?php

/*
 * BillsToolsTest.php
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

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Mcp\Tools\BillsReadTool;
use FireflyIII\Mcp\Tools\BillsWriteTool;
use FireflyIII\Models\Bill;
use Tests\integration\Api\Mcp\Concerns\CallsApiTools;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class BillsToolsTest extends TestCase
{
    use CallsApiTools;
    use EnsuresPassportKeys;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\BillsReadTool
     * @covers \FireflyIII\Mcp\Tools\BillsWriteTool
     */
    public function testSubscriptionRoundTrip(): void
    {
        $user    = $this->createAuthenticatedUser();
        $created = $this->okJson($user, BillsWriteTool::class, [
            'action' => 'create',
            'data'   => [
                'name'        => 'Streaming',
                'amount_min'  => '9.99',
                'amount_max'  => '12.99',
                'date'        => '2026-01-05',
                'repeat_freq' => 'monthly'
            ]
        ]);
        $id = $created['data']['id'];
        self::assertSame(
            ['id' => $id, 'name' => 'Streaming', 'amount_min' => '9.99', 'amount_max' => '12.99'],
            array_intersect_key($created['data'], array_flip(['id', 'name', 'amount_min', 'amount_max']))
        );

        // a transaction linked to the subscription shows up as paid in the period and in its transactions
        $asset   = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $expense = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Streamer');
        $group   = $this->storeTransaction($user, [
            'type'           => TransactionTypeEnum::WITHDRAWAL->value,
            'date'           => Carbon::parse('2026-02-05'),
            'amount'         => '10.99',
            'description'    => 'Streaming February',
            'source_id'      => $asset->id,
            'destination_id' => $expense->id,
            'bill_id'        => $id
        ]);

        $period = ['start' => '2026-02-01', 'end' => '2026-02-28'];
        $get    = $this->okJson($user, BillsReadTool::class, ['action' => 'get', 'id' => $id, ...$period]);
        self::assertSame([(int) $group->id], array_map('intval', array_column($get['data']['paid_dates'], 'transaction_group_id')));

        $transactions = $this->okJson($user, BillsReadTool::class, ['action' => 'transactions', 'id' => $id, ...$period]);
        self::assertSame([(int) $group->id], array_column($transactions['data'], 'id'));

        $list = $this->okJson($user, BillsReadTool::class, ['action' => 'list']);
        self::assertSame([$id], array_column($list['data'], 'id'));

        $updated = $this->okJson($user, BillsWriteTool::class, ['action' => 'update', 'id' => $id, 'data' => ['name' => 'Video streaming', 'active' => false]]);
        self::assertSame('Video streaming', $updated['data']['name']);
        self::assertFalse($updated['data']['active']);

        $error = $this->invalidArgument($user, BillsWriteTool::class, ['action' => 'delete', 'id' => $id], 'confirm');
        self::assertSame(['transactions' => 1], $error['dependants']);
        $this->invokeTool($user, BillsWriteTool::class, ['action' => 'delete', 'id' => $id, 'confirm' => true])->assertHasNoErrors();
        self::assertFalse(Bill::whereKey($id)->exists());
    }

    /**
     * The API has no active filter, so bills-read pages the active rows itself.
     *
     * @covers \FireflyIII\Mcp\Tools\BillsReadTool
     */
    public function testListActiveOnly(): void
    {
        $user     = $this->createAuthenticatedUser();
        $active   = [$this->createBill($user, 'Internet'), $this->createBill($user, 'Phone')];
        $inactive = $this->createBill($user, 'Gym');
        $inactive->update(['active' => false]);

        $all = $this->okJson($user, BillsReadTool::class, ['action' => 'list']);
        self::assertCount(3, $all['data']);

        $first = $this->okJson($user, BillsReadTool::class, ['action' => 'list', 'active' => true, 'limit' => 1]);
        self::assertSame([$active[0]->id], array_column($first['data'], 'id'));
        self::assertSame(2, $first['meta']['pagination']['total']);
        self::assertSame(2, $first['meta']['next_page']);

        $second = $this->okJson($user, BillsReadTool::class, ['action' => 'list', 'active' => true, 'limit' => 1, 'page' => 2]);
        self::assertSame([$active[1]->id], array_column($second['data'], 'id'));
        self::assertFalse($second['meta']['has_more']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\BillsWriteTool
     */
    public function testValidationErrorsUseTheSharedFormat(): void
    {
        $user = $this->createAuthenticatedUser();

        $this->invalidArgument(
            $user,
            BillsWriteTool::class,
            ['action' => 'create', 'data' => ['name' => 'X', 'amount_min' => '5', 'amount_max' => '6', 'date' => '2026-01-01', 'repeat_freq' => 'hourly']],
            'data.repeat_freq'
        );
        $this->invalidArgument($user, BillsWriteTool::class, ['action' => 'create', 'data' => ['name' => 'X']], 'data.amount_min');
        $this->invalidArgument($user, BillsWriteTool::class, ['action' => 'create', 'data' => 'name=X'], 'data');
        $this->invalidArgument($user, BillsWriteTool::class, ['action' => 'archive'], 'action');
        $this->invalidArgument($user, BillsReadTool::class, ['action' => 'list', 'start' => '01/02/2026'], 'start');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
