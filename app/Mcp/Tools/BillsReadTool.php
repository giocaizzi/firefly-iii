<?php

/*
 * BillsReadTool.php
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

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Subscriptions (bills): list, get, or the transactions linked to one. start/end add paid_dates and pay_dates for the period.')]
#[IsReadOnly]
#[IsIdempotent]
final class BillsReadTool extends AbstractApiTool
{
    public const array COMPACT = [
        'id',
        'name',
        'amount_min',
        'amount_max',
        'currency_code',
        'repeat_freq',
        'skip',
        'active',
        'object_group_title',
        'next_expected_match',
        'paid_dates',
        'pay_dates'
    ];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'get', 'transactions'])->required(),
            'id'     => $schema->integer()->description('For get/transactions.'),
            'start'  => $schema->string()->description('YYYY-MM-DD.'),
            'end'    => $schema->string()->description('YYYY-MM-DD.'),
            ...$this->readSchema($schema)
        ];
    }

    protected function run(Request $request): Response
    {
        $action = $this->actionArg($request, ['list', 'get', 'transactions']);
        $query  = $this->listQuery($request);

        return match ($action) {
            'list'  => $this->respondApi($this->api('GET', 'bills', $query), $request, self::COMPACT),
            'get'   => $this->respondApi($this->api('GET', sprintf('bills/%s', $this->idArg($request)), $query), $request, self::COMPACT),
            default => $this->respondTransactions($this->api('GET', sprintf('bills/%s/transactions', $this->idArg($request)), $query), $request)
        };
    }
}
