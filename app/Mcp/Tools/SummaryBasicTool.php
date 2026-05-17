<?php

/*
 * SummaryBasicTool.php
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
use FireflyIII\Mcp\Support\Aggregates\SummaryBasic;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Dashboard digest: balance/spent/earned/bills/left-to-spend/net-worth per currency. Defaults to the current month.')]
#[IsReadOnly]
#[IsIdempotent]
final class SummaryBasicTool extends AbstractMcpTool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user  = auth()->user();
        $now   = Carbon::now(config('app.timezone'));
        $start = $this->parseDate($request->get('start')) ?? $now->copy()->startOfMonth();
        $end   = $this->parseDate($request->get('end')) ?? $now->copy()->endOfMonth();

        $summary = new SummaryBasic();
        $data    = $summary->build($user, $start, $end);

        return Response::json([
            'data' => $data,
            'meta' => [
                'start' => $start->format('Y-m-d'),
                'end'   => $end->format('Y-m-d')
            ]
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'start'         => $schema
                ->string()
                ->format('date')
                ->description('Inclusive start date (YYYY-MM-DD); defaults to the first day of the current month.'),
            'end'           => $schema
                ->string()
                ->format('date')
                ->description('Inclusive end date (YYYY-MM-DD); defaults to the last day of the current month.'),
            'verbose'       => $schema->boolean()->description('Has no structural effect for this tool; included for API symmetry.'),
            'include_nulls' => $schema->boolean()->description('Preserve null-valued attributes in the output.')
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
