<?php

/*
 * PiggyBanksToolsTest.php
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
use FireflyIII\Mcp\Tools\PiggyBanksReadTool;
use FireflyIII\Mcp\Tools\PiggyBanksWriteTool;
use FireflyIII\Models\PiggyBank;
use Tests\integration\Api\Mcp\Concerns\CallsApiTools;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class PiggyBanksToolsTest extends TestCase
{
    use CallsApiTools;
    use EnsuresPassportKeys;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\PiggyBanksReadTool
     */
    public function testGetReturnsCompactRowAndVerboseRawDocument(): void
    {
        $user  = $this->createAuthenticatedUser();
        $piggy = $this->createPiggyBank($user, 'Holiday');

        $get = $this->okJson($user, PiggyBanksReadTool::class, ['action' => 'get', 'id' => $piggy->id]);
        self::assertSame('Holiday', $get['data']['name']);
        self::assertArrayNotHasKey('created_at', $get['data']);

        $verbose = $this->okJson($user, PiggyBanksReadTool::class, ['action' => 'get', 'id' => $piggy->id, 'verbose' => true]);
        self::assertSame('piggy-banks', $verbose['data']['type']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\PiggyBanksReadTool
     * @covers \FireflyIII\Mcp\Tools\PiggyBanksWriteTool
     */
    public function testPiggyBankRoundTripWithMoney(): void
    {
        $user    = $this->createAuthenticatedUser();
        $savings = $this->createAccount($user, AccountTypeEnum::ASSET, 'Savings');
        $revenue = $this->createAccount($user, AccountTypeEnum::REVENUE, 'Employer');
        $this->storeTransaction($user, [
            'type'           => TransactionTypeEnum::DEPOSIT->value,
            'date'           => Carbon::now()->subDay(),
            'amount'         => '500',
            'description'    => 'Salary',
            'source_id'      => $revenue->id,
            'destination_id' => $savings->id
        ]);

        $created = $this->okJson($user, PiggyBanksWriteTool::class, [
            'action' => 'create',
            'data'   => [
                'name'                      => 'Bike',
                'target_amount'             => '300',
                'start_date'                => '2026-01-01',
                'transaction_currency_code' => 'EUR',
                'accounts'                  => [['account_id' => $savings->id]]
            ]
        ]);
        $id = $created['data']['id'];
        self::assertSame('300', $created['data']['target_amount']);

        $added = $this->okJson($user, PiggyBanksWriteTool::class, ['action' => 'add', 'id' => $id, 'account_id' => $savings->id, 'amount' => '40']);
        self::assertSame('40', $added['data']['current_amount']);
        $removed = $this->okJson($user, PiggyBanksWriteTool::class, ['action' => 'remove', 'id' => $id, 'account_id' => $savings->id, 'amount' => '15']);
        self::assertSame('25', $removed['data']['current_amount']);

        $this->invalidArgument(
            $user,
            PiggyBanksWriteTool::class,
            ['action' => 'remove', 'id' => $id, 'account_id' => $savings->id, 'amount' => '26'],
            'amount'
        );
        $this->invalidArgument($user, PiggyBanksWriteTool::class, ['action' => 'add', 'id' => $id, 'account_id' => $savings->id, 'amount' => '-1'], 'amount');
        $this->invalidArgument(
            $user,
            PiggyBanksWriteTool::class,
            ['action' => 'add', 'id' => $id, 'account_id' => $revenue->id, 'amount' => '1'],
            'account_id'
        );

        $events = $this->okJson($user, PiggyBanksReadTool::class, ['action' => 'events', 'id' => $id]);
        self::assertEqualsCanonicalizing(['40', '-15'], array_column($events['data'], 'amount'));

        $list = $this->okJson($user, PiggyBanksReadTool::class, ['action' => 'list', 'fields' => ['id', 'name']]);
        self::assertSame([['id' => $id, 'name' => 'Bike']], $list['data']);

        $updated = $this->okJson($user, PiggyBanksWriteTool::class, ['action' => 'update', 'id' => $id, 'data' => ['name' => 'Road bike']]);
        self::assertSame('Road bike', $updated['data']['name']);

        $error = $this->invalidArgument($user, PiggyBanksWriteTool::class, ['action' => 'delete', 'id' => $id], 'confirm');
        self::assertSame(['events' => 2], $error['dependants']);
        $this->invokeTool($user, PiggyBanksWriteTool::class, ['action' => 'delete', 'id' => $id, 'confirm' => true])->assertHasNoErrors();
        self::assertFalse(PiggyBank::whereKey($id)->exists());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
