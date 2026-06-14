<?php

/*
 * ChartData.php
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
use FireflyIII\Models\Account;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetLimitRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Repositories\Budget\OperationsRepositoryInterface;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\User;
use Illuminate\Support\Collection;

/**
 * Lean chart-data lift for MCP. Restricted to the three chart
 * types: account_balances, category_expenses,
 * budget_vs_actual.
 *
 * Single-currency, no convertToPrimary gymnastics — each row carries its own
 * currency_code so AI clients can group as they please. This matches the
 * compactness goal of the reducer pipeline over the REST chart
 * payload's denser per-row currency metadata.
 */
final class ChartData
{
    /**
     * @return list<array<string, mixed>>
     */
    public function accountBalances(User $user, Carbon $start, Carbon $end): array
    {
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);

        $accounts = $repository->getActiveAccountsByType([
            AccountTypeEnum::ASSET->value,
            AccountTypeEnum::DEFAULT->value
        ]);

        $out = [];
        /** @var Account $account */
        foreach ($accounts as $account) {
            $currency = $repository->getAccountCurrency($account);
            $range    = Steam::finalAccountBalanceInRange($account, $start->copy()->startOfDay(), $end->copy()->endOfDay(), false);
            $entries  = [];
            foreach ($range as $date => $row) {
                $entries[(string) $date] = (string) ($row['balance'] ?? '0');
            }
            $out[] = [
                'account_id'    => (int) $account->id,
                'account_name'  => (string) $account->name,
                'currency_code' => $currency->code ?? null,
                'entries'       => $entries
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function budgetVsActual(User $user, Carbon $start, Carbon $end): array
    {
        $budgetRepository = app(BudgetRepositoryInterface::class);
        $blRepository     = app(BudgetLimitRepositoryInterface::class);
        $opsRepository    = app(OperationsRepositoryInterface::class);
        $budgetRepository->setUser($user);
        $blRepository->setUser($user);
        $opsRepository->setUser($user);

        $budgets = $budgetRepository->getActiveBudgets();
        $out     = [];

        /** @var Budget $budget */
        foreach ($budgets as $budget) {
            $limits = $blRepository->getBudgetLimits($budget, $start, $end);
            $spent  = $opsRepository->listExpenses($start, $end, null, new Collection()->push($budget));

            /** @var array<int, array<string, mixed>> $spent */
            foreach ($spent as $currencyId => $block) {
                $row = [
                    'budget_id'     => (int) $budget->id,
                    'budget_name'   => (string) $budget->name,
                    'currency_id'   => (int) $currencyId,
                    'currency_code' => (string) ($block['currency_code'] ?? ''),
                    'budgeted'      => '0',
                    'spent'         => '0',
                    'left'          => '0',
                    'overspent'     => '0'
                ];
                $journalSum = '0';
                foreach ((array) ($block['budgets'][$budget->id]['transaction_journals'] ?? []) as $journal) {
                    $journalSum = bcadd($journalSum, (string) $journal['amount']);
                }
                $row['spent'] = $journalSum;
                $limit        = $this->matchLimit((int) $currencyId, $limits);
                if ($limit instanceof BudgetLimit) {
                    $row['budgeted']  = (string) $limit->amount;
                    $row['left']      = bcsub($row['budgeted'], bcmul($row['spent'], '-1'));
                    $row['overspent'] = bcmul($row['left'], '-1');
                    $row['left']      = 1 === bccomp($row['left'], '0') ? $row['left'] : '0';
                    $row['overspent'] = 1 === bccomp($row['overspent'], '0') ? $row['overspent'] : '0';
                }
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function categoryExpenses(User $user, Carbon $start, Carbon $end): array
    {
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);

        $accounts = $repository->getAccountsByType([
            AccountTypeEnum::DEBT->value,
            AccountTypeEnum::LOAN->value,
            AccountTypeEnum::MORTGAGE->value,
            AccountTypeEnum::ASSET->value
        ]);

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector
            ->setUser($user)
            ->setRange($start, $end)
            ->withAccountInformation()
            ->withCategoryInformation()
            ->setXorAccounts($accounts)
            ->setTypes([TransactionTypeEnum::WITHDRAWAL->value]);
        $journals = $collector->getExtractedJournals();

        $buckets = [];
        foreach ($journals as $journal) {
            $categoryId   = (int) ($journal['category_id'] ?? 0);
            $categoryName = (string) ($journal['category_name'] ?? '(no category)');
            $currencyCode = (string) $journal['currency_code'];
            $key          = $categoryId . '|' . $currencyCode;

            $buckets[$key] ??= [
                'category_id'   => $categoryId > 0 ? $categoryId : null,
                'category_name' => $categoryName,
                'currency_code' => $currencyCode,
                'total'         => '0'
            ];
            $buckets[$key]['total'] = bcadd((string) $buckets[$key]['total'], Steam::positive((string) $journal['amount']));
        }

        return array_values($buckets);
    }

    private function matchLimit(int $currencyId, Collection $limits): null|BudgetLimit
    {
        /** @var BudgetLimit $limit */
        foreach ($limits as $limit) {
            if ($limit->transaction_currency_id === $currencyId) {
                return $limit;
            }
        }

        return null;
    }
}
