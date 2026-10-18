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
use FireflyIII\Mcp\Support\JsonApiReducer;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Per-currency balance, spent, earned, bills, left-to-spend and net worth. Default: current month.')]
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
            'data' => $this->includeNulls($request) ? $data : JsonApiReducer::prune($data),
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
            'start'         => $schema->string()->format('date')->description('From date (YYYY-MM-DD); default: start of month.'),
            'end'           => $schema->string()->format('date')->description('To date (YYYY-MM-DD); default: end of month.'),
            'verbose'       => $schema->boolean()->description('No effect here.'),
            'include_nulls' => $schema->boolean()->description('Keep null values.')
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
