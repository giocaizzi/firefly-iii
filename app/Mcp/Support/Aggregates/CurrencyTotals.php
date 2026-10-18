<?php

/*
 * CurrencyTotals.php
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

use FireflyIII\Support\Facades\Steam;

/**
 * Per-currency, per-type running totals shared by the transaction aggregate and tag stats.
 *
 * Sign convention: every type sum is a positive magnitude in the transaction's own currency
 * (foreign amounts are not converted). `withdrawal` and `deposit` are always present;
 * `net` = deposit - withdrawal. Transfers and other types appear only when present and are
 * not part of `net`.
 */
final class CurrencyTotals
{
    /** @var array<string, array{count: int, decimals: int, types: array<string, string>}> */
    private array $currencies = [];

    /**
     * Snake-case type key: "Opening balance" -> "opening_balance".
     */
    public static function typeKey(string $type): string
    {
        return str_replace(' ', '_', strtolower(trim($type)));
    }

    /**
     * @param string $amount signed or unsigned; the magnitude is used
     */
    public function add(string $type, string $currency, int $decimals, string $amount, int $count = 1): void
    {
        $key = self::typeKey($type);
        if (!array_key_exists($currency, $this->currencies)) {
            $this->currencies[$currency] = ['count' => 0, 'decimals' => $decimals, 'types' => ['withdrawal' => '0', 'deposit' => '0']];
        }
        $this->currencies[$currency]['count']       += $count;
        $this->currencies[$currency]['types'][$key] = bcadd($this->currencies[$currency]['types'][$key] ?? '0', Steam::positive($amount));
    }

    public function count(): int
    {
        return array_sum(array_column($this->currencies, 'count'));
    }

    /**
     * @return list<array<string, int|string>>
     */
    public function toArray(): array
    {
        ksort($this->currencies);
        $rows = [];
        foreach ($this->currencies as $currency => $entry) {
            $row = ['currency' => $currency, 'count' => $entry['count']];
            foreach ($entry['types'] as $type => $sum) {
                $row[$type] = Steam::bcround($sum, $entry['decimals']);
            }
            $row['net'] = Steam::bcround(bcsub($entry['types']['deposit'], $entry['types']['withdrawal']), $entry['decimals']);
            $rows[]     = $row;
        }

        return $rows;
    }
}
