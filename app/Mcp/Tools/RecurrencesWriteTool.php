<?php

/*
 * RecurrencesWriteTool.php
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

use FireflyIII\Mcp\Support\InvalidArgument;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Create, update or delete a recurring transaction, or trigger it now (creates its transaction for date).')]
#[IsDestructive]
final class RecurrencesWriteTool extends AbstractApiTool
{
    private const array ACTIONS = ['create', 'update', 'delete', 'trigger'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(self::ACTIONS)->required(),
            'id'     => $schema->integer()->description('For update/delete/trigger.'),
            'date'   => $schema->string()->description('trigger: YYYY-MM-DD occurrence date.'),
            ...$this->writeSchema(
                $schema,
                'type (withdrawal|deposit|transfer), title, first_date, repeat_until or nr_of_repetitions, apply_rules, active, notes, '
                . 'repetitions [{type (daily|weekly|ndom|monthly|yearly), moment, skip, weekend}], '
                . 'transactions [{description, amount, currency_code, source_id, destination_id, budget_id, category_name, tags}] (update needs them too).'
            )
        ];
    }

    protected function run(Request $request): Response
    {
        $action = $this->actionArg($request, self::ACTIONS);
        if ('trigger' !== $action) {
            return $this->writeEntity($request, $action, 'recurrences', 'recurring transaction', RecurrencesReadTool::COMPACT, [
                'created transactions' => 'transactions'
            ]);
        }
        $id   = $this->idArg($request);
        $date = $this->dateArg($request, 'date');
        if (null === $date) {
            InvalidArgument::fail('date', 'date is required to trigger (YYYY-MM-DD).');
        }
        $document = $this->api('POST', sprintf('recurrences/%s/trigger', $id), ['date' => $date->format('Y-m-d')]);
        $this->audit(['action' => $action, 'object' => 'recurring transaction', 'id' => $id, 'date' => $date->format('Y-m-d')]);

        return $this->respondTransactions($document, $request);
    }
}
