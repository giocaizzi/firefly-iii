<?php

/*
 * ChartDataTool.php
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
use FireflyIII\Mcp\Support\Aggregates\ChartData;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Chart data for one of three lean chart types: account_balances, category_expenses, or budget_vs_actual.')]
#[IsReadOnly]
#[IsIdempotent]
final class ChartDataTool extends AbstractMcpTool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $type = (string) $request->get('chart_type', '');
        if (!in_array($type, ['account_balances', 'category_expenses', 'budget_vs_actual'], true)) {
            return Response::error('chart_type must be one of: account_balances, category_expenses, budget_vs_actual.');
        }

        $start = $this->parseDate($request->get('start'));
        $end   = $this->parseDate($request->get('end'));
        if (null === $start || null === $end) {
            return Response::error('Both `start` and `end` are required and must be ISO 8601 dates.');
        }

        $service = new ChartData();
        $rows    = match ($type) {
            'account_balances'  => $service->accountBalances($user, $start, $end),
            'category_expenses' => $service->categoryExpenses($user, $start, $end),
            'budget_vs_actual'  => $service->budgetVsActual($user, $start, $end)
        };

        return Response::json([
            'data' => $rows,
            'meta' => [
                'chart_type' => $type,
                'start'      => $start->format('Y-m-d'),
                'end'        => $end->format('Y-m-d')
            ]
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'chart_type'    => $schema
                ->string()
                ->required()
                ->enum(['account_balances', 'category_expenses', 'budget_vs_actual'])
                ->description('Which chart payload to return.'),
            'start'         => $schema->string()->format('date')->required()->description('Inclusive start date (YYYY-MM-DD).'),
            'end'           => $schema->string()->format('date')->required()->description('Inclusive end date (YYYY-MM-DD).'),
            'verbose'       => $schema->boolean()->description('Has no structural effect for this tool; included for API symmetry.'),
            'include_nulls' => $schema->boolean()->description('Preserve null-valued attributes in the reduced output.')
        ];
    }

    private function parseDate(mixed $raw): null|Carbon
    {
        if (!is_string($raw) || '' === trim($raw)) {
            return null;
        }
        try {
            return Carbon::parse($raw, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
