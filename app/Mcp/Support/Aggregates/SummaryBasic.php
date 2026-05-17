<?php

/*
 * SummaryBasic.php
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

namespace FireflyIII\Mcp\Support\Aggregates;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Helpers\Report\NetWorthInterface;
use FireflyIII\Models\Account;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Bill\BillRepositoryInterface;
use FireflyIII\Repositories\Budget\AvailableBudgetRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Repositories\Budget\OperationsRepositoryInterface;
use FireflyIII\Repositories\Currency\CurrencyRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Http\Api\ExchangeRateConverter;
use FireflyIII\Support\Report\Summarizer\TransactionSummarizer;
use FireflyIII\User;
use Illuminate\Support\Collection;

/**
 * Compact lift of app/Api/V1/Controllers/Summary/BasicController::basic() into a
 * non-HTTP support class (WIP_MCP D-036). All four sub-views (balance, bills,
 * left-to-spend, net-worth) are merged and keyed the same way the REST endpoint
 * does so MCP and REST surface identical dashboard cards.
 */
final class SummaryBasic
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function build(User $user, Carbon $start, Carbon $end): array
    {
        $accountRepository  = $this->scope($user, app(AccountRepositoryInterface::class));
        $billRepository     = $this->scope($user, app(BillRepositoryInterface::class));
        $budgetRepository   = $this->scope($user, app(BudgetRepositoryInterface::class));
        $abRepository       = $this->scope($user, app(AvailableBudgetRepositoryInterface::class));
        $opsRepository      = $this->scope($user, app(OperationsRepositoryInterface::class));
        $currencyRepository = $this->scope($user, app(CurrencyRepositoryInterface::class));
        $abRepository->cleanup();

        $rows = array_merge(
            $this->balance($start, $end, $currencyRepository),
            $this->bills($start, $end, $billRepository, $currencyRepository),
            $this->leftToSpend($start, $end, $abRepository, $budgetRepository, $opsRepository, $currencyRepository),
            $this->netWorth($user, $end, $accountRepository)
        );

        $keyed = [];
        foreach ($rows as $row) {
            if (array_key_exists('key', $row)) {
                $keyed[(string) $row['key']] = $row;
            }
        }

        return $keyed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function balance(Carbon $start, Carbon $end, CurrencyRepositoryInterface $currencyRepository): array
    {
        $primary          = Amount::getPrimaryCurrency();
        $convertToPrimary = Amount::convertToPrimary();
        $summarizer       = new TransactionSummarizer();
        $currencies       = [$primary->id => $primary];

        /** @var GroupCollectorInterface $collectorIncome */
        $collectorIncome = app(GroupCollectorInterface::class);
        $incomes         = $summarizer->groupByCurrencyId(
            $collectorIncome->setRange($start, $end)->setTypes([TransactionTypeEnum::DEPOSIT->value])->getExtractedJournals(),
            'positive',
            false
        );

        /** @var GroupCollectorInterface $collectorExpense */
        $collectorExpense = app(GroupCollectorInterface::class);
        $expenses         = $summarizer->groupByCurrencyId(
            $collectorExpense->setRange($start, $end)->setTypes([TransactionTypeEnum::WITHDRAWAL->value])->getExtractedJournals(),
            'negative',
            false
        );

        $sums = [];
        if ($convertToPrimary) {
            $converter   = new ExchangeRateConverter();
            $newExpenses = [$primary->id => $this->zeroRow($primary)];
            $newIncomes  = [$primary->id => $this->zeroRow($primary)];
            $sums        = [$primary->id => $this->zeroRow($primary)];

            foreach ([$expenses, $incomes] as $idx => $entries) {
                foreach ($entries as $entry) {
                    $cid = (int) $entry['currency_id'];
                    if ($cid === $primary->id) {
                        $sums[$primary->id]['sum'] = bcadd($sums[$primary->id]['sum'], (string) $entry['sum']);
                        if (0 === $idx) {
                            $newExpenses[$primary->id]['sum'] = bcadd($newExpenses[$primary->id]['sum'], (string) $entry['sum']);
                        }
                        if (1 === $idx) {
                            $newIncomes[$primary->id]['sum'] = bcadd($newIncomes[$primary->id]['sum'], (string) $entry['sum']);
                        }

                        continue;
                    }
                    $currencies[$cid] ??= $currencyRepository->find($cid);
                    if (null === $currencies[$cid]) {
                        continue;
                    }
                    $converted                 = $converter->convert($currencies[$cid], $primary, $start, (string) $entry['sum']);
                    $sums[$primary->id]['sum'] = bcadd($sums[$primary->id]['sum'], $converted);
                    if (0 === $idx) {
                        $newExpenses[$primary->id]['sum'] = bcadd($newExpenses[$primary->id]['sum'], $converted);
                    }
                    if (1 === $idx) {
                        $newIncomes[$primary->id]['sum'] = bcadd($newIncomes[$primary->id]['sum'], $converted);
                    }
                }
            }
            $incomes  = $newIncomes;
            $expenses = $newExpenses;
        }
        if (!$convertToPrimary) {
            foreach ([$expenses, $incomes] as $entries) {
                foreach ($entries as $entry) {
                    $cid        = (int) $entry['currency_id'];
                    $sums[$cid] ??= [
                        'currency_id'             => $entry['currency_id'],
                        'currency_code'           => $entry['currency_code'],
                        'currency_symbol'         => $entry['currency_symbol'],
                        'currency_decimal_places' => $entry['currency_decimal_places'],
                        'sum'                     => '0'
                    ];
                    $sums[$cid]['sum'] = bcadd((string) $sums[$cid]['sum'], (string) $entry['sum']);
                }
            }
        }

        $out = [];
        foreach (array_keys($sums) as $cid) {
            $currency = $currencies[$cid] ?? $currencyRepository->find((int) $cid);
            if (null === $currency) {
                continue;
            }
            $out[] = [
                'key'             => sprintf('balance-in-%s', $currency->code),
                'monetary_value'  => $sums[$cid]['sum'] ?? '0',
                'currency_id'     => (string) $currency->id,
                'currency_code'   => $currency->code,
                'currency_symbol' => $currency->symbol
            ];
            $out[] = [
                'key'             => sprintf('spent-in-%s', $currency->code),
                'monetary_value'  => $expenses[$cid]['sum'] ?? '0',
                'currency_id'     => (string) $currency->id,
                'currency_code'   => $currency->code,
                'currency_symbol' => $currency->symbol
            ];
            $out[] = [
                'key'             => sprintf('earned-in-%s', $currency->code),
                'monetary_value'  => $incomes[$cid]['sum'] ?? '0',
                'currency_id'     => (string) $currency->id,
                'currency_code'   => $currency->code,
                'currency_symbol' => $currency->symbol
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bills(Carbon $start, Carbon $end, BillRepositoryInterface $billRepository, CurrencyRepositoryInterface $currencyRepository): array
    {
        $primary          = Amount::getPrimaryCurrency();
        $convertToPrimary = Amount::convertToPrimary();
        $paid             = $billRepository->sumPaidInRange($start, $end);
        $unpaid           = $billRepository->sumUnpaidInRange($start, $end);

        if ($convertToPrimary) {
            $converter  = new ExchangeRateConverter();
            $newPaid    = [[
                'id'             => $primary->id,
                'symbol'         => $primary->symbol,
                'code'           => $primary->code,
                'decimal_places' => $primary->decimal_places,
                'sum'            => '0'
            ]];
            $newUnpaid  = [[
                'id'             => $primary->id,
                'symbol'         => $primary->symbol,
                'code'           => $primary->code,
                'decimal_places' => $primary->decimal_places,
                'sum'            => '0'
            ]];
            $currencies = [$primary->id => $primary];
            foreach ([$paid, $unpaid] as $idx => $list) {
                foreach ($list as $item) {
                    $cid = (int) $item['id'];
                    if ($cid === $primary->id) {
                        if (0 === $idx) {
                            $newPaid[0]['sum'] = bcadd((string) $newPaid[0]['sum'], (string) $item['sum']);
                        }
                        if (1 === $idx) {
                            $newUnpaid[0]['sum'] = bcadd((string) $newUnpaid[0]['sum'], (string) $item['sum']);
                        }

                        continue;
                    }
                    $currencies[$cid] ??= $currencyRepository->find($cid);
                    if (null === $currencies[$cid]) {
                        continue;
                    }
                    $converted = $converter->convert($currencies[$cid], $primary, $start, (string) $item['sum']);
                    if (0 === $idx) {
                        $newPaid[0]['sum'] = bcadd((string) $newPaid[0]['sum'], $converted);
                    }
                    if (1 === $idx) {
                        $newUnpaid[0]['sum'] = bcadd((string) $newUnpaid[0]['sum'], $converted);
                    }
                }
            }
            $paid   = $newPaid;
            $unpaid = $newUnpaid;
        }

        $out = [];
        foreach ($paid as $info) {
            $amount = bcmul((string) $info['sum'], '-1');
            $out[]  = [
                'key'             => sprintf('bills-paid-in-%s', $info['code']),
                'monetary_value'  => $amount,
                'currency_id'     => (string) $info['id'],
                'currency_code'   => $info['code'],
                'currency_symbol' => $info['symbol']
            ];
        }
        foreach ($unpaid as $info) {
            $amount = bcmul((string) $info['sum'], '-1');
            $out[]  = [
                'key'             => sprintf('bills-unpaid-in-%s', $info['code']),
                'monetary_value'  => $amount,
                'currency_id'     => (string) $info['id'],
                'currency_code'   => $info['code'],
                'currency_symbol' => $info['symbol']
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function leftToSpend(
        Carbon $start,
        Carbon $end,
        AvailableBudgetRepositoryInterface $abRepository,
        BudgetRepositoryInterface $budgetRepository,
        OperationsRepositoryInterface $opsRepository,
        CurrencyRepositoryInterface $currencyRepository
    ): array {
        $today      = today(config('app.timezone'));
        $available  = $abRepository->getAvailableBudgetWithCurrency($start, $end);
        $budgets    = $budgetRepository->getActiveBudgets();
        $spent      = $opsRepository->sumExpenses($start, $end, null, $budgets, null, true);
        $days       = (int) $today->diffInDays($end, true) + 1;
        $currencies = [];
        $out        = [];

        foreach ($available as $currencyId => $availableBudget) {
            $currencies[$currencyId] ??= $currencyRepository->find((int) $currencyId);
            if (null === $currencies[$currencyId]) {
                continue;
            }
            $out[(int) $currencyId] = [
                'key'                  => sprintf('left-to-spend-in-%s', $currencies[$currencyId]->code),
                'no_available_budgets' => false,
                'monetary_value'       => $availableBudget,
                'currency_id'          => (string) $currencies[$currencyId]->id,
                'currency_code'        => $currencies[$currencyId]->code,
                'currency_symbol'      => $currencies[$currencyId]->symbol
            ];
        }
        foreach ($spent as $row) {
            $cid    = (int) $row['currency_id'];
            $amount = (string) ($available[$cid] ?? '0');
            if (0 === bccomp($amount, '0')) {
                continue;
            }
            $left      = bcadd($amount, (string) $row['sum']);
            $out[$cid] = [
                'key'                  => sprintf('left-to-spend-in-%s', $row['currency_code']),
                'no_available_budgets' => false,
                'monetary_value'       => $left,
                'currency_id'          => (string) $row['currency_id'],
                'currency_code'        => $row['currency_code'],
                'currency_symbol'      => $row['currency_symbol']
            ];
        }
        if ([] === $out) {
            $days  = (int) $start->diffInDays($end, true) + 1;
            $spent = $opsRepository->sumExpenses($start, $end, null, new Collection());
            foreach ($spent as $row) {
                $cid       = (int) $row['currency_id'];
                $out[$cid] = [
                    'key'                  => sprintf('left-to-spend-in-%s', $row['currency_code']),
                    'no_available_budgets' => true,
                    'monetary_value'       => (string) $row['sum'],
                    'currency_id'          => (string) $row['currency_id'],
                    'currency_code'        => $row['currency_code'],
                    'currency_symbol'      => $row['currency_symbol']
                ];
            }
            unset($days); // value kept as side-information in $today calc; reset for clarity
        }

        return array_values($out);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function netWorth(User $user, Carbon $end, AccountRepositoryInterface $accountRepository): array
    {
        /** @var NetWorthInterface $netWorthHelper */
        $netWorthHelper = app(NetWorthInterface::class);
        $netWorthHelper->setUser($user);

        $allAccounts = $accountRepository->getActiveAccountsByType([
            AccountTypeEnum::ASSET->value,
            AccountTypeEnum::DEFAULT->value,
            AccountTypeEnum::LOAN->value,
            AccountTypeEnum::MORTGAGE->value,
            AccountTypeEnum::DEBT->value
        ]);
        $filtered = $allAccounts->filter(static function (Account $account) use ($accountRepository): bool {
            $includeNetWorth = $accountRepository->getMetaValue($account, 'include_net_worth');

            return null === $includeNetWorth || '1' === $includeNetWorth;
        });
        $netWorthSet = $netWorthHelper->byAccounts($filtered, $end);

        $out = [];
        foreach ($netWorthSet as $key => $data) {
            if ('pc' === $key) {
                continue;
            }
            $amount = (string) $data['balance'];
            if (0 === bccomp($amount, '0')) {
                continue;
            }
            $out[] = [
                'key'             => sprintf('net-worth-in-%s', $data['currency_code']),
                'monetary_value'  => $amount,
                'currency_id'     => (string) $data['currency_id'],
                'currency_code'   => $data['currency_code'],
                'currency_symbol' => $data['currency_symbol']
            ];
        }

        return $out;
    }

    /**
     * @template T
     *
     * @param T $repository
     *
     * @return T
     */
    private function scope(User $user, mixed $repository): mixed
    {
        if (method_exists($repository, 'setUser')) {
            $repository->setUser($user);
        }

        return $repository;
    }

    /**
     * @return array<string, mixed>
     */
    private function zeroRow(\FireflyIII\Models\TransactionCurrency $currency): array
    {
        return [
            'currency_id'             => $currency->id,
            'currency_code'           => $currency->code,
            'currency_symbol'         => $currency->symbol,
            'currency_decimal_places' => $currency->decimal_places,
            'sum'                     => '0'
        ];
    }
}
