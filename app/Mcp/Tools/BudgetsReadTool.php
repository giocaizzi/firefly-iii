<?php

/*
 * BudgetsReadTool.php
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

#[Description('Budgets, budget limits and available budgets: list or get. start/end add spent per period.')]
#[IsReadOnly]
#[IsIdempotent]
final class BudgetsReadTool extends AbstractApiTool
{
    private const array COMPACT = [
        'budget'    => ['id', 'name', 'active', 'auto_budget_type', 'auto_budget_amount', 'auto_budget_period', 'currency_code', 'spent'],
        'limit'     => ['id', 'budget_id', 'start', 'end', 'period', 'amount', 'currency_code', 'spent'],
        'available' => ['id', 'start', 'end', 'amount', 'currency_code', 'spent_in_budgets', 'spent_outside_budgets']
    ];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action'    => $schema->string()->enum(['list', 'get'])->required(),
            'kind'      => $schema->string()->enum(array_keys(self::COMPACT))->description('Default budget.'),
            'id'        => $schema->integer()->description('For get.'),
            'budget_id' => $schema->integer()->description('Limits of one budget (required to get a limit).'),
            'start'     => $schema->string()->description('YYYY-MM-DD.'),
            'end'       => $schema->string()->description('YYYY-MM-DD.'),
            ...$this->readSchema($schema)
        ];
    }

    protected function run(Request $request): Response
    {
        $action = $this->actionArg($request, ['list', 'get']);
        $kind   = $this->enumArg($request, 'kind', array_keys(self::COMPACT), 'budget');
        $query  = $this->listQuery($request);
        $get    = 'get' === $action;

        $path = match ($kind) {
            'budget'    => $get ? sprintf('budgets/%s', $this->idArg($request)) : 'budgets',
            'available' => $get ? sprintf('available-budgets/%s', $this->idArg($request)) : 'available-budgets',
            default     => $this->limitPath($request, $get)
        };

        return $this->respondApi($this->api('GET', $path, $query), $request, self::COMPACT[$kind]);
    }

    private function limitPath(Request $request, bool $get): string
    {
        if ($get) {
            return sprintf('budgets/%s/limits/%s', $this->idArg($request, 'budget_id'), $this->idArg($request));
        }
        if (null !== $request->get('budget_id')) {
            return sprintf('budgets/%s/limits', $this->idArg($request, 'budget_id'));
        }

        return 'budget-limits';
    }
}
