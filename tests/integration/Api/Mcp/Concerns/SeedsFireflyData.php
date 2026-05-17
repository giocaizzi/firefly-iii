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
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
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
