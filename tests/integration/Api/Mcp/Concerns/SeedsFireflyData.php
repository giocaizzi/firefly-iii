<?php

/*
 * SeedsFireflyData.php
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

namespace Tests\integration\Api\Mcp\Concerns;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountMeta;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\Bill;
use FireflyIII\Models\Budget;
use FireflyIII\Models\Category;
use FireflyIII\Models\PiggyBank;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\Webhook;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\User;

/**
 * Shared fixture helpers for the MCP test suite.
 *
 * Provides small, deterministic seed methods so each test sets up exactly the rows it needs
 * — keeps tests fast and isolated. Currency is resolved against the seeded EUR row.
 */
trait SeedsFireflyData
{
    protected function createAccount(User $user, AccountTypeEnum $type, string $name): Account
    {
        $accountType = AccountType::whereType($type->value)->firstOrFail();
        $currency    = $this->primaryCurrency();
        $account     = Account::create([
            'user_id'         => $user->id,
            'user_group_id'   => $user->user_group_id,
            'account_type_id' => $accountType->id,
            'name'            => $name,
            'active'          => true
        ]);

        // Stamp the currency the AccountRepository uses to compute getAccountCurrency().
        AccountMeta::create([
            'account_id' => $account->id,
            'name'       => 'currency_id',
            'data'       => $currency->id
        ]);

        return $account;
    }

    protected function createBill(User $user, string $name = 'Internet'): Bill
    {
        $currency = $this->primaryCurrency();

        return Bill::create([
            'user_id'                 => $user->id,
            'user_group_id'           => $user->user_group_id,
            'name'                    => $name,
            'match'                   => $name,
            'amount_min'              => '10.00',
            'amount_max'              => '30.00',
            'date'                    => Carbon::now(),
            'repeat_freq'             => 'monthly',
            'skip'                    => 0,
            'automatch'               => true,
            'active'                  => true,
            'transaction_currency_id' => $currency->id
        ]);
    }

    protected function createBudget(User $user, string $name = 'Daily'): Budget
    {
        return Budget::create([
            'user_id'       => $user->id,
            'user_group_id' => $user->user_group_id,
            'name'          => $name,
            'active'        => true,
            'order'         => 1
        ]);
    }

    protected function createCategory(User $user, string $name = 'Food'): Category
    {
        return Category::create([
            'user_id'       => $user->id,
            'user_group_id' => $user->user_group_id,
            'name'          => $name
        ]);
    }

    protected function createPiggyBank(User $user, string $name = 'Vacation'): PiggyBank
    {
        $asset    = $this->createAccount($user, AccountTypeEnum::ASSET, sprintf('Piggy host %s', $name));
        $currency = $this->primaryCurrency();
        $piggy    = PiggyBank::create([
            'name'                    => $name,
            'order'                   => 1,
            'target_amount'           => '100.00',
            'start_date'              => Carbon::now()->toDateString(),
            'active'                  => true,
            'transaction_currency_id' => $currency->id
        ]);
        $piggy->accounts()->attach($asset, ['current_amount' => '0', 'native_current_amount' => '0']);

        return $piggy;
    }

    protected function createWebhook(User $user, string $title = 'Test webhook'): Webhook
    {
        // The repository filters to "upgraded" rows: delivery=1, response=1, trigger=1.
        return Webhook::create([
            'user_id'       => $user->id,
            'user_group_id' => $user->user_group_id,
            'title'         => $title,
            'url'           => 'https://example.test/hook',
            'secret'        => 'a-test-secret',
            'active'        => true,
            'trigger'       => 1,
            'response'      => 1,
            'delivery'      => 1
        ]);
    }

    /**
     * Persist a withdrawal via the canonical TransactionGroupRepository.
     *
     * Using the repository (rather than building rows by hand) guarantees that the journal
     * and its meta/links match the shape every collector + transformer expects.
     */
    protected function createWithdrawal(
        User $user,
        Account $source,
        Account $destination,
        string $amount = '12.50',
        string $description = 'Lunch'
    ): TransactionGroup {
        return $this->storeTransaction($user, [
            'type'             => TransactionTypeEnum::WITHDRAWAL->value,
            'date'             => Carbon::now(),
            'amount'           => $amount,
            'description'      => $description,
            'source_id'        => $source->id,
            'destination_id'   => $destination->id,
            'destination_name' => $destination->name,
            'reconciled'       => false
        ]);
    }

    protected function primaryCurrency(): TransactionCurrency
    {
        $currency = TransactionCurrency::whereCode('EUR')->first();
        if (null === $currency) {
            $currency = TransactionCurrency::create([
                'code'           => 'EUR',
                'name'           => 'Euro',
                'symbol'         => '€',
                'decimal_places' => 2,
                'enabled'        => true
            ]);
        }

        return $currency;
    }

    /**
     * @param array<string, mixed> $line
     */
    protected function storeTransaction(User $user, array $line): TransactionGroup
    {
        $payload = [
            'user'         => $user,
            'user_group'   => $user->userGroup,
            'transactions' => [$line]
        ];

        return app(TransactionGroupRepositoryInterface::class)->store($payload);
    }
}
