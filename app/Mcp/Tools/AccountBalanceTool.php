<?php

/*
 * AccountBalanceTool.php
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

namespace FireflyIII\Mcp\Tools;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Mcp\Support\InvalidArgument;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

use function Safe\preg_match;

/**
 * Balance of asset, cash and liability accounts at the end of one or more days.
 *
 * Uses the same calculation as the account overview (Steam::accountsBalancesOptimized, no
 * conversion): the sum of every transaction up to and including the day, plus the virtual
 * balance in the account's own currency. Accounts holding several currencies list each one.
 */
#[Description('Balance per currency of asset, cash or liability accounts at the end of one or more dates.')]
#[IsReadOnly]
#[IsIdempotent]
final class AccountBalanceTool extends AbstractMcpTool
{
    public const int MAX_ACCOUNTS = 50;

    public const int MAX_DATES = 60;

    private const array TYPES = [
        AccountTypeEnum::ASSET,
        AccountTypeEnum::DEFAULT,
        AccountTypeEnum::CASH,
        AccountTypeEnum::CREDITCARD,
        AccountTypeEnum::DEBT,
        AccountTypeEnum::LOAN,
        AccountTypeEnum::MORTGAGE
    ];

    /** @var array<string, int> */
    private array $decimalPlaces = [];

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user       = auth()->user();
        $accountIds = $this->accountIdsArg($request);
        $dates      = $this->datesArg($request);

        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);
        $accounts = $repository->getAccountsById($accountIds);
        $allowed  = array_map(static fn(AccountTypeEnum $type): string => $type->value, self::TYPES);
        foreach ($accountIds as $accountId) {
            /** @var null|Account $account */
            $account = $accounts->firstWhere('id', $accountId);
            if (null === $account) {
                InvalidArgument::fail('account_ids', sprintf('Account #%d not found.', $accountId), ['account_id' => $accountId]);
            }
            if (!in_array($account->accountType->type, $allowed, true)) {
                InvalidArgument::fail(
                    'account_ids',
                    sprintf('Account #%d is a %s; only asset, cash and liability accounts have a balance here.', $accountId, $account->accountType->type),
                    [
                        'account_id' => $accountId,
                        'allowed'    => $allowed
                    ]
                );
            }
        }

        $primary  = Amount::getPrimaryCurrency();
        $balances = [];
        foreach ($dates as $date) {
            $balances[$date->format('Y-m-d')] = Steam::accountsBalancesOptimized($accounts, $date->copy()->endOfDay(), $primary, false);
        }

        $rows = [];
        foreach ($accountIds as $accountId) {
            /** @var Account $account */
            $account  = $accounts->firstWhere('id', $accountId);
            $currency = $repository->getAccountCurrency($account) ?? $primary;
            $entries  = [];
            foreach ($balances as $day => $perAccount) {
                $entries[] = ['date' => $day, 'balances' => $this->perCurrency($perAccount[$accountId] ?? [], $currency)];
            }
            $rows[] = [
                'account_id' => $accountId,
                'name'       => (string) $account->name,
                'type'       => (string) $account->accountType->type,
                'currency'   => $currency->code,
                'entries'    => $entries
            ];
        }

        return Response::json(['data' => $rows]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id'  => $schema->integer()->description('One account id.'),
            'account_ids' => $schema->array()->items($schema->integer())->description(sprintf('Several account ids (max %d).', self::MAX_ACCOUNTS)),
            'date'        => $schema->string()->format('date')->description('Balance at the end of this day (YYYY-MM-DD). Default today.'),
            'dates'       => $schema->array()->items($schema->string())->description(sprintf('Several days (max %d).', self::MAX_DATES))
        ];
    }

    /**
     * @return list<int>
     */
    private function accountIdsArg(Request $request): array
    {
        $ids = [];
        if (null !== $request->get('account_id')) {
            $ids[] = $this->positiveIntArg($request, 'account_id', 0);
        }
        $raw = $request->get('account_ids');
        if (null !== $raw) {
            if (!is_array($raw)) {
                InvalidArgument::fail('account_ids', 'account_ids must be a list of account ids.', ['received' => $raw]);
            }
            foreach ($raw as $id) {
                if (is_string($id) && 1 === preg_match('/^\d+$/', trim($id))) {
                    $id = (int) trim($id);
                }
                if (!is_int($id) || $id < 1) {
                    InvalidArgument::fail('account_ids', 'account_ids must be a list of positive integers.', ['received' => $raw]);
                }
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ([] === $ids) {
            InvalidArgument::fail('account_id', 'Pass account_id or account_ids.');
        }
        if (count($ids) > self::MAX_ACCOUNTS) {
            InvalidArgument::fail('account_ids', sprintf('At most %d accounts per call.', self::MAX_ACCOUNTS), ['max' => self::MAX_ACCOUNTS]);
        }

        return $ids;
    }

    /**
     * @return list<Carbon> unique, ascending
     */
    private function datesArg(Request $request): array
    {
        $dates = [];
        $date  = $this->dateArg($request, 'date');
        if (null !== $date) {
            $dates[] = $date;
        }
        $raw = $request->get('dates');
        if (null !== $raw) {
            if (!is_array($raw)) {
                InvalidArgument::fail('dates', 'dates must be a list of YYYY-MM-DD dates.', ['received' => $raw]);
            }
            foreach (array_values($raw) as $index => $value) {
                $dates[] = $this->dateArg(new Request(['dates' => $value]), 'dates') ?? InvalidArgument::fail('dates', sprintf('dates[%d] is empty.', $index));
            }
        }
        if ([] === $dates) {
            $dates[] = Carbon::now(config('app.timezone'));
        }
        $unique = [];
        foreach ($dates as $entry) {
            $unique[$entry->format('Y-m-d')] = $entry->copy()->startOfDay();
        }
        ksort($unique);
        if (count($unique) > self::MAX_DATES) {
            InvalidArgument::fail('dates', sprintf('At most %d dates per call.', self::MAX_DATES), ['max' => self::MAX_DATES]);
        }

        return array_values($unique);
    }

    private function decimals(string $code, TransactionCurrency $accountCurrency): int
    {
        if ($code === $accountCurrency->code) {
            return (int) $accountCurrency->decimal_places;
        }
        $this->decimalPlaces[$code] ??= (int) (TransactionCurrency::where('code', $code)->first()->decimal_places ?? 2);

        return $this->decimalPlaces[$code];
    }

    /**
     * Steam's per-account entry: 'balance' (account currency, incl. virtual balance) plus one key per currency code.
     *
     * @param array<string, string> $entry
     *
     * @return array<string, string>
     */
    private function perCurrency(array $entry, TransactionCurrency $currency): array
    {
        $result = [];
        foreach ($entry as $key => $value) {
            if ('balance' === $key || 'pc_balance' === $key) {
                continue;
            }
            $result[$key] = $value;
        }
        if (!array_key_exists($currency->code, $result)) {
            $result[$currency->code] = (string) ($entry['balance'] ?? '0');
        }
        ksort($result);
        foreach ($result as $code => $value) {
            $result[$code] = Steam::bcround($value, $this->decimals($code, $currency));
        }

        return $result;
    }
}
