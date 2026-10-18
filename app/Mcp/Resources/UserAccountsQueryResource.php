<?php

/*
 * UserAccountsQueryResource.php
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

namespace FireflyIII\Mcp\Resources;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Mcp\Support\InvalidArgument;
use FireflyIII\Mcp\Support\JsonApiReducer;
use FireflyIII\Mcp\Tools\AbstractMcpTool;
use FireflyIII\Models\Account;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\JsonApi\Enrichments\AccountEnrichment;
use FireflyIII\Transformers\AccountTransformer;
use FireflyIII\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;
use League\Fractal\Manager;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Resource\Collection as FractalCollection;
use League\Fractal\Serializer\JsonApiSerializer;

use function Safe\preg_match;

/**
 * Paged and filtered variant of firefly-iii://user/accounts.
 *
 * The plain URI keeps returning the whole unpaged list (UserAccountsResource); this template
 * takes a query string: page (default 1), limit (default 25, max 200), type (asset, expense,
 * revenue, liability) and query (name contains, case-insensitive). Rows keep the same reduced
 * shape; meta carries the pagination block plus has_more / next_page.
 */
#[MimeType('application/json')]
#[Description('Paged/filtered active accounts. Query string: page, limit (max 200), type (asset|expense|revenue|liability), query (name contains).')]
final class UserAccountsQueryResource extends Resource implements HasUriTemplate
{
    private const array TYPE_FILTERS = [
        'asset'     => [AccountTypeEnum::ASSET->value],
        'expense'   => [AccountTypeEnum::EXPENSE->value],
        'revenue'   => [AccountTypeEnum::REVENUE->value],
        'liability' => [
            AccountTypeEnum::LOAN->value,
            AccountTypeEnum::DEBT->value,
            AccountTypeEnum::MORTGAGE->value,
            AccountTypeEnum::CREDITCARD->value,
            AccountTypeEnum::LIABILITY_CREDIT->value
        ]
    ];

    public function handle(Request $request): Response
    {
        parse_str((string) $request->get('params', ''), $params);
        [$page, $limit, $types, $search] = $this->parseParams($params);

        /** @var User $user */
        $user       = auth()->user();
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);

        $accounts = $repository->getActiveAccountsByType($types);
        if ('' !== $search) {
            $accounts = $accounts->filter(static fn(Account $account): bool => str_contains(mb_strtolower((string) $account->name), mb_strtolower($search)));
        }
        $count    = $accounts->count();
        $accounts = $accounts->slice(($page - 1) * $limit, $limit)->values();

        $enrichment = new AccountEnrichment();
        $enrichment->setUser($user);
        $accounts = $enrichment->enrich($accounts);

        $manager = new Manager();
        $manager->setSerializer(new JsonApiSerializer());

        $resource = new FractalCollection($accounts, app(AccountTransformer::class), 'accounts');
        $resource->setPaginator(new IlluminatePaginatorAdapter(new LengthAwarePaginator($accounts, $count, $limit, $page)));

        $reduced = app(JsonApiReducer::class)->reduce($manager->createData($resource)->toArray());
        $current = (int) ($reduced['meta']['pagination']['current_page'] ?? $page);
        $hasMore = $current < (int) ($reduced['meta']['pagination']['total_pages'] ?? 0);

        $reduced['meta']['has_more'] = $hasMore;
        if ($hasMore) {
            $reduced['meta']['next_page'] = $current + 1;
        }

        return Response::text(json_encode($reduced, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('firefly-iii://user/accounts?{params}');
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{0: int, 1: int, 2: list<string>, 3: string}
     */
    private function parseParams(array $params): array
    {
        $int = static function (string $key, int $default) use ($params): int {
            $raw = $params[$key] ?? null;
            if (null === $raw) {
                return $default;
            }
            if (!is_string($raw) || 1 !== preg_match('/^\d+$/', $raw) || (int) $raw < 1) {
                InvalidArgument::fail($key, sprintf('%s must be a positive integer.', $key), ['received' => $raw]);
            }

            return (int) $raw;
        };
        $page  = $int('page', 1);
        $limit = $int('limit', AbstractMcpTool::DEFAULT_LIMIT);
        if ($limit > AbstractMcpTool::MAX_LIMIT) {
            InvalidArgument::fail('limit', sprintf('limit must be at most %d.', AbstractMcpTool::MAX_LIMIT), ['max' => AbstractMcpTool::MAX_LIMIT]);
        }

        $types = UserAccountsResource::TYPES;
        if (array_key_exists('type', $params)) {
            $type = is_string($params['type']) ? strtolower(trim($params['type'])) : '';
            if (!array_key_exists($type, self::TYPE_FILTERS)) {
                InvalidArgument::fail('type', 'type must be one of: asset, expense, revenue, liability.', ['allowed' => array_keys(self::TYPE_FILTERS)]);
            }
            $types = self::TYPE_FILTERS[$type];
        }
        $search = is_string($params['query'] ?? null) ? trim($params['query']) : '';

        return [$page, $limit, $types, $search];
    }
}
