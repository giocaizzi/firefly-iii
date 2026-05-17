<?php

/*
 * ListAccountsTool.php
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

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\JsonApi\Enrichments\AccountEnrichment;
use FireflyIII\Transformers\AccountTransformer;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Pagination\LengthAwarePaginator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Resource\Collection as FractalCollection;

#[Description("List the authenticated user's accounts, optionally filtered by type or active status.")]
#[IsReadOnly]
#[IsIdempotent]
final class ListAccountsTool extends AbstractMcpTool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user       = auth()->user();
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);

        [$page, $limit] = $this->pageAndLimit($request);

        $types = $this->resolveTypes((string) $request->get('type', ''));
        $repository->resetAccountOrder();
        $collection = $repository->getAccountsByType($types);

        if (true === $request->get('active')) {
            $collection = $collection->filter(static fn(Account $account): bool => true === (bool) $account->active);
        }

        $count    = $collection->count();
        $offset   = ($page - 1) * $limit;
        $accounts = $collection->slice($offset, $limit);

        $enrichment = new AccountEnrichment();
        $enrichment->setUser($user);
        $accounts = $enrichment->enrich($accounts);

        $paginator = new LengthAwarePaginator($accounts, $count, $limit, $page);

        $transformer = app(AccountTransformer::class);
        $resource    = new FractalCollection($accounts, $transformer, 'accounts');
        $resource->setPaginator(new IlluminatePaginatorAdapter($paginator));

        $document = $this->jsonApiManager()->createData($resource)->toArray();

        return $this->respondJsonApi($document, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type'          => $schema
                ->string()
                ->description(
                    'Account-type filter. Accepts a single enum value (e.g. "asset", "expense", "revenue", "liability") or a comma-separated list. Omit for all normal types.'
                ),
            'active'        => $schema->boolean()->description('When true, return only active accounts.'),
            'page'          => $schema->integer()->description('Page number (1-based).'),
            'limit'         => $schema->integer()->description("Items per page; defaults to the user's listPageSize preference."),
            'verbose'       => $schema->boolean()->description('Return the raw JSON:API document instead of the reduced shape.'),
            'include_nulls' => $schema->boolean()->description('Preserve null-valued attributes in the reduced output.')
        ];
    }

    /**
     * @return array<int, string>
     */
    private function resolveTypes(string $type): array
    {
        $type = trim($type);
        if ('' === $type) {
            return [
                AccountTypeEnum::ASSET->value,
                AccountTypeEnum::EXPENSE->value,
                AccountTypeEnum::REVENUE->value,
                AccountTypeEnum::LOAN->value,
                AccountTypeEnum::DEBT->value,
                AccountTypeEnum::MORTGAGE->value
            ];
        }

        $aliases = [
            'asset'     => [AccountTypeEnum::ASSET->value, AccountTypeEnum::DEFAULT->value],
            'expense'   => [AccountTypeEnum::EXPENSE->value, AccountTypeEnum::BENEFICIARY->value],
            'revenue'   => [AccountTypeEnum::REVENUE->value],
            'liability' => [
                AccountTypeEnum::DEBT->value,
                AccountTypeEnum::LOAN->value,
                AccountTypeEnum::MORTGAGE->value,
                AccountTypeEnum::CREDITCARD->value
            ],
            'cash'      => [AccountTypeEnum::CASH->value]
        ];

        $result = [];
        foreach (explode(',', $type) as $part) {
            $key = strtolower(trim($part));
            if ('' === $key) {
                continue;
            }
            if (array_key_exists($key, $aliases)) {
                $result = array_merge($result, $aliases[$key]);

                continue;
            }
            // Treat unknown tokens as raw type strings (matches existing AccountFilter behavior).
            $result[] = $part;
        }

        $result = array_values(array_unique($result));

        return [] === $result ? [AccountTypeEnum::ASSET->value] : $result;
    }
}
