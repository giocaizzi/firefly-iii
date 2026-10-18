<?php

/*
 * AccountBalanceToolTest.php
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
use FireflyIII\Mcp\Tools\AccountBalanceTool;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers AccountBalanceTool: end-of-day balances per date, several accounts, cash and liability
 * accounts, and the argument errors.
 *
 * @internal
 *
 * @coversNothing
 */
final class AccountBalanceToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\AccountBalanceTool
     */
    public function testGivenBadArgumentsWhenAskingThenRejects(): void
    {
        $user    = $this->createAuthenticatedUser();
        $expense = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop');
        $asset   = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');

        $this->invokeTool($user, AccountBalanceTool::class, [])->assertHasErrors(['Pass account_id or account_ids']);
        $this->invokeTool($user, AccountBalanceTool::class, ['account_id' => 999_999])->assertHasErrors(['not found']);
        $this->invokeTool($user, AccountBalanceTool::class, ['account_id' => $expense->id])->assertHasErrors(['only asset, cash and liability']);
        $this->invokeTool($user, AccountBalanceTool::class, ['account_id' => $asset->id, 'dates' => ['2026-13-01']])->assertHasErrors(['YYYY-MM-DD']);
        $this->invokeTool($user, AccountBalanceTool::class, ['account_ids' => 'nope'])->assertHasErrors(['account_ids must be a list']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\AccountBalanceTool
     */
    public function testGivenCashAndLiabilityAccountsWhenAskingThenBothAreReturned(): void
    {
        $user = $this->createAuthenticatedUser();
        $cash = $this->createAccount($user, AccountTypeEnum::CASH, 'Cash account');
        $loan = $this->createAccount($user, AccountTypeEnum::LOAN, 'Car loan');
        $shop = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop');
        $this->line($user, TransactionTypeEnum::WITHDRAWAL, '2026-02-01', '12.00', $loan->id, $shop->id);

        $payload = $this->decodeJson($this->invokeTool($user, AccountBalanceTool::class, ['account_ids' => [$loan->id, $cash->id], 'date' => '2026-02-01']));

        self::assertSame([$loan->id, $cash->id], array_column($payload['data'], 'account_id'));
        self::assertSame('-12.00', $payload['data'][0]['entries'][0]['balances']['EUR']);
        self::assertSame('0.00', $payload['data'][1]['entries'][0]['balances']['EUR']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\AccountBalanceTool
     */
    public function testGivenNoDateWhenAskingThenUsesToday(): void
    {
        $user     = $this->createAuthenticatedUser();
        $checking = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');

        $payload = $this->decodeJson($this->invokeTool($user, AccountBalanceTool::class, ['account_id' => $checking->id]));

        self::assertSame(Carbon::now()->format('Y-m-d'), $payload['data'][0]['entries'][0]['date']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\AccountBalanceTool
     */
    public function testGivenTransactionsWhenAskingSeveralDatesThenReturnsEndOfDayBalances(): void
    {
        $user     = $this->createAuthenticatedUser();
        $checking = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $employer = $this->createAccount($user, AccountTypeEnum::REVENUE, 'Employer');
        $shop     = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop');
        $this->line($user, TransactionTypeEnum::DEPOSIT, '2026-01-01', '100.00', $employer->id, $checking->id);
        $this->line($user, TransactionTypeEnum::WITHDRAWAL, '2026-01-15', '30.00', $checking->id, $shop->id);

        $payload = $this->decodeJson($this->invokeTool($user, AccountBalanceTool::class, [
            'account_id' => $checking->id,
            'dates'      => ['2026-01-15', '2025-12-31', '2026-01-01']
        ]));

        self::assertCount(1, $payload['data']);
        $row = $payload['data'][0];
        self::assertSame($checking->id, $row['account_id']);
        self::assertSame('EUR', $row['currency']);
        self::assertSame(
            [
                ['date' => '2025-12-31', 'balances' => ['EUR' => '0.00']],
                ['date' => '2026-01-01', 'balances' => ['EUR' => '100.00']],
                ['date' => '2026-01-15', 'balances' => ['EUR' => '70.00']]
            ],
            $row['entries']
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }

    private function line(\FireflyIII\User $user, TransactionTypeEnum $type, string $date, string $amount, int $source, int $destination): void
    {
        $this->storeTransaction($user, [
            'type'           => $type->value,
            'date'           => Carbon::parse($date),
            'amount'         => $amount,
            'description'    => 'balance seed',
            'source_id'      => $source,
            'destination_id' => $destination,
            'reconciled'     => false
        ]);
    }
}
