<?php

/*
 * RecurrencesReadTool.php
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

#[Description('Recurring transactions: list, get (repetitions carry the next occurrences), or the transactions one created.')]
#[IsReadOnly]
#[IsIdempotent]
final class RecurrencesReadTool extends AbstractApiTool
{
    public const array COMPACT = [
        'id',
        'type',
        'title',
        'first_date',
        'latest_date',
        'repeat_until',
        'nr_of_repetitions',
        'active',
        'repetitions',
        'transactions'
    ];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'get', 'transactions'])->required(),
            'id'     => $schema->integer()->description('For get/transactions.'),
            'start'  => $schema->string()->description('transactions: YYYY-MM-DD.'),
            'end'    => $schema->string()->description('transactions: YYYY-MM-DD.'),
            ...$this->readSchema($schema)
        ];
    }

    protected function run(Request $request): Response
    {
        $action = $this->actionArg($request, ['list', 'get', 'transactions']);
        $query  = $this->listQuery($request);

        return match ($action) {
            'list'  => $this->respondApi($this->api('GET', 'recurrences', $query), $request, self::COMPACT),
            'get'   => $this->respondApi($this->api('GET', sprintf('recurrences/%s', $this->idArg($request))), $request, self::COMPACT),
            default => $this->respondTransactions($this->api('GET', sprintf('recurrences/%s/transactions', $this->idArg($request)), $query), $request)
        };
    }
}
