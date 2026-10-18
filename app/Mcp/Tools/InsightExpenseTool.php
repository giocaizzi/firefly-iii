<?php

/*
 * InsightExpenseTool.php
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
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Mcp\Support\Aggregates\InsightSummariser;
use FireflyIII\Mcp\Support\JsonApiReducer;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Total expenses per currency over a date range.')]
#[IsReadOnly]
#[IsIdempotent]
final class InsightExpenseTool extends AbstractMcpTool
{
    public function handle(Request $request): Response
    {
        $start = $this->parseDate($request->get('start'));
        $end   = $this->parseDate($request->get('end'));
        if (null === $start || null === $end) {
            return Response::error('Both `start` and `end` are required and must be ISO 8601 dates.');
        }

        $accounts   = $this->resolveAssetAccounts($request->get('account_ids'));
        $summariser = new InsightSummariser();
        $rows       = $summariser->expenseTotal($start, $end, $accounts);

        // The Insight endpoints return a flat list of currency rows; expose the same
        // shape under data so the reducer doesn't strip anything material.
        return Response::json([
            'data' => $this->includeNulls($request) ? $rows : JsonApiReducer::prune($rows),
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
            'start'         => $schema->string()->format('date')->required()->description('From date, inclusive (YYYY-MM-DD).'),
            'end'           => $schema->string()->format('date')->required()->description('To date, inclusive (YYYY-MM-DD).'),
            'account_ids'   => $schema->array()->description('Asset account ids to include; default: all.'),
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

    private function resolveAssetAccounts(mixed $rawIds): Collection
    {
        /** @var User $user */
        $user       = auth()->user();
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);

        if (is_array($rawIds) && [] !== $rawIds) {
            $ids = array_values(array_filter(array_map(static fn(mixed $v): int => (int) $v, $rawIds), static fn(int $v): bool => $v > 0));
            if ([] !== $ids) {
                return $repository->getAccountsById($ids);
            }
        }

        return $repository->getAccountsByType([AccountTypeEnum::ASSET->value, AccountTypeEnum::DEFAULT->value]);
    }
}
