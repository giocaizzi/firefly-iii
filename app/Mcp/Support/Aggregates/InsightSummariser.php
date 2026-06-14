<?php

/*
 * InsightSummariser.php
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
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Steam;
use Illuminate\Support\Collection;

/**
 * Lifts the per-currency income/expense totalling logic from
 * app/Api/V1/Controllers/Insight/{Expense,Income}/PeriodController::total()
 * into a non-HTTP support class for MCP tools.
 *
 * Pure aggregation only — currency conversion follows the same convertToPrimary
 * branching the REST endpoints use; account filtering is the caller's responsibility.
 */
final class InsightSummariser
{
    /**
     * Sum withdrawals out of $accounts in [start, end], grouped by currency.
     *
     * Mirrors Insight\Expense\PeriodController::total().
     *
     * @return list<array{difference: string, difference_float: float, currency_id: string, currency_code: string}>
     */
    public function expenseTotal(Carbon $start, Carbon $end, Collection $accounts): array
    {
        $response         = [];
        $convertToPrimary = Amount::convertToPrimary();
        $primary          = Amount::getPrimaryCurrency();

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setTypes([TransactionTypeEnum::WITHDRAWAL->value])->setRange($start, $end)->setSourceAccounts($accounts);
        $journals = $collector->getExtractedJournals();

        foreach ($journals as $journal) {
            $amount       = '0';
            $currencyId   = (int) $journal['currency_id'];
            $currencyCode = (string) $journal['currency_code'];
            if ($convertToPrimary) {
                $amount = Amount::getAmountFromJournal($journal);
                if ($primary->id !== (int) $journal['currency_id'] && $primary->id !== (int) $journal['foreign_currency_id']) {
                    $currencyId   = $primary->id;
                    $currencyCode = $primary->code;
                }
                if ($primary->id !== (int) $journal['currency_id'] && $primary->id === (int) $journal['foreign_currency_id']) {
                    $currencyId   = (int) $journal['foreign_currency_id'];
                    $currencyCode = (string) $journal['foreign_currency_code'];
                }
            }
            if (!$convertToPrimary) {
                $amount = (string) $journal['amount'];
            }

            $response[$currencyId] ??= [
                'difference'       => '0',
                'difference_float' => 0.0,
                'currency_id'      => (string) $currencyId,
                'currency_code'    => $currencyCode
            ];
            $response[$currencyId]['difference']       = bcadd((string) $response[$currencyId]['difference'], $amount);
            $response[$currencyId]['difference_float'] = (float) $response[$currencyId]['difference'];
        }

        return array_values($response);
    }

    /**
     * Sum deposits into $accounts in [start, end], grouped by currency.
     *
     * Mirrors Insight\Income\PeriodController::total().
     *
     * @return list<array{difference: string, difference_float: float, currency_id: string, currency_code: string}>
     */
    public function incomeTotal(Carbon $start, Carbon $end, Collection $accounts): array
    {
        $response         = [];
        $convertToPrimary = Amount::convertToPrimary();
        $primary          = Amount::getPrimaryCurrency();

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setTypes([TransactionTypeEnum::DEPOSIT->value])->setRange($start, $end)->setDestinationAccounts($accounts);
        $journals = $collector->getExtractedJournals();

        foreach ($journals as $journal) {
            $currencyId   = (int) $journal['currency_id'];
            $currencyCode = (string) $journal['currency_code'];
            $field        = $convertToPrimary && $currencyId !== $primary->id ? 'pc_amount' : 'amount';

            if ($convertToPrimary && $currencyId !== $primary->id) {
                $currencyId   = $primary->id;
                $currencyCode = $primary->code;
            }
            if ($convertToPrimary && (int) $journal['currency_id'] !== $primary->id && $primary->id === (int) ($journal['foreign_currency_id'] ?? 0)) {
                $field = 'foreign_amount';
            }

            $response[$currencyId] ??= [
                'difference'       => '0',
                'difference_float' => 0.0,
                'currency_id'      => (string) $currencyId,
                'currency_code'    => $currencyCode
            ];
            $response[$currencyId]['difference']       = bcadd((string) $response[$currencyId]['difference'], Steam::positive((string) $journal[$field]));
            $response[$currencyId]['difference_float'] = (float) $response[$currencyId]['difference'];
        }

        return array_values($response);
    }
}
