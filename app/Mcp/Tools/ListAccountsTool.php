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
use FireflyIII\Mcp\Support\InvalidArgument;
use FireflyIII\Mcp\Support\TransactionProjector;
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

#[Description('List accounts by type, name or active status, with current balances.')]
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
        $query = trim((string) $request->get('query', ''));
        if ('' !== $query) {
            $collection = $collection->filter(static fn(Account $account): bool => str_contains(mb_strtolower((string) $account->name), mb_strtolower($query)));
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
            'type'   => $schema->string()->description('asset, expense, revenue, liability, cash (comma-separated allowed). Default: all.'),
            'query'  => $schema->string()->description('Name contains (case-insensitive).'),
            'active' => $schema->boolean()->description('true = active accounts only.'),
            ...$this->pagingSchema($schema),
            ...$this->compactSchema($schema),
            ...$this->outputFlagsSchema($schema)
        ];
    }

    /**
     * @param array<string, mixed> $jsonApiDocument
     *
     * @return array<string, mixed>
     */
    protected function shape(array $jsonApiDocument, Request $request): array
    {
        $reduced = parent::shape($jsonApiDocument, $request);
        if (!(bool) $request->get('compact', true) || !is_array($reduced['data'] ?? null)) {
            return $reduced;
        }
        $map             = [
            'id'              => 'id',
            'name'            => 'name',
            'type'            => 'type',
            'currency'        => 'currency_code',
            'current_balance' => 'current_balance',
            'active'          => 'active'
        ];
        $reduced['data'] = array_map(function (array $row) use ($map, $request): array {
            $row['current_balance'] = TransactionProjector::trimAmount(null !== ($row['current_balance'] ?? null) ? (string) $row['current_balance'] : null);

            return $this->pick($row, $map, $this->includeNulls($request));
        }, $reduced['data']);

        return $reduced;
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
            // Raw Firefly type strings (e.g. "Asset account") are accepted as-is; anything else is rejected.
            $raw = array_values(array_filter(AccountTypeEnum::cases(), static fn(AccountTypeEnum $case): bool => mb_strtolower($case->value) === $key));
            if ([] === $raw) {
                InvalidArgument::fail('type', sprintf('Unknown account type "%s".', trim($part)), ['allowed' => array_keys($aliases)]);
            }
            $result[] = $raw[0]->value;
        }

        $result = array_values(array_unique($result));

        return [] === $result ? [AccountTypeEnum::ASSET->value] : $result;
    }
}
