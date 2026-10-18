<?php

/*
 * BudgetsWriteTool.php
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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Create, update or delete a budget or a budget limit (amount for start..end).')]
#[IsDestructive]
final class BudgetsWriteTool extends AbstractApiTool
{
    private const array COMPACT = [
        'budget' => ['id', 'name', 'active', 'auto_budget_type', 'auto_budget_amount', 'auto_budget_period', 'currency_code'],
        'limit'  => ['id', 'budget_id', 'start', 'end', 'amount', 'currency_code']
    ];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action'    => $schema->string()->enum(['create', 'update', 'delete'])->required(),
            'kind'      => $schema->string()->enum(array_keys(self::COMPACT)),
            'id'        => $schema->integer(),
            'budget_id' => $schema->integer()->description('Budget of the limit.'),
            ...$this->writeSchema(
                $schema,
                'budget: name, active, notes, auto_budget_type (none|reset|rollover|adjusted), auto_budget_amount, auto_budget_period, auto_budget_currency_code; limit: start, end, amount, currency_code, notes.'
            )
        ];
    }

    protected function run(Request $request): Response
    {
        $action = $this->actionArg($request, ['create', 'update', 'delete']);
        if ('limit' === $this->enumArg($request, 'kind', array_keys(self::COMPACT), 'budget')) {
            return $this->writeEntity(
                $request,
                $action,
                sprintf('budgets/%s/limits', $this->idArg($request, 'budget_id')),
                'budget limit',
                self::COMPACT['limit']
            );
        }

        return $this->writeEntity($request, $action, 'budgets', 'budget', self::COMPACT['budget'], [
            'budget limits' => 'limits',
            'transactions'  => 'transactions'
        ]);
    }
}
