<?php

/*
 * ListTransactionsTool.php
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
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\JsonApi\Enrichments\TransactionGroupEnrichment;
use FireflyIII\Transformers\TransactionGroupTransformer;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Resource\Collection as FractalCollection;

#[Description('List transactions in a date range, optionally filtered by account or transaction type.')]
#[IsReadOnly]
#[IsIdempotent]
final class ListTransactionsTool extends AbstractMcpTool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = auth()->user();
        [$page, $limit] = $this->pageAndLimit($request);

        $types = $this->mapTypes((string) $request->get('type', 'default'));

        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($user)->withAPIInformation()->setLimit($limit)->setPage($page)->setTypes($types);

        $start = $this->parseDate($request->get('start'));
        $end   = $this->parseDate($request->get('end'));
        if (null !== $start || null !== $end) {
            $collector->setRange($start ?? Carbon::createFromTimestamp(0), $end ?? Carbon::now()->endOfDay());
        }

        $accountIds = [];
        $accountId  = $request->get('account_id');
        if (null !== $accountId) {
            $accountIds[] = (int) $accountId;
        }
        $accountName = $request->get('account_name');
        if (null === $accountId && is_string($accountName) && '' !== $accountName) {
            $accountRepository = app(AccountRepositoryInterface::class);
            $accountRepository->setUser($user);
            $found = $accountRepository->findByName($accountName, []);
            if (null !== $found) {
                $accountIds[] = (int) $found->id;
            }
        }
        if ([] !== $accountIds) {
            $accountRepository = app(AccountRepositoryInterface::class);
            $accountRepository->setUser($user);
            $accounts = $accountRepository->getAccountsById($accountIds);
            if ($accounts->count() > 0) {
                $collector->setAccounts($accounts);
            }
        }

        $paginator = $collector->getPaginatedGroups();

        $enrichment = new TransactionGroupEnrichment();
        $enrichment->setUser($user);
        $groups = $enrichment->enrich($paginator->getCollection());

        $transformer = app(TransactionGroupTransformer::class);
        $resource    = new FractalCollection($groups, $transformer, 'transactions');
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
            'start'         => $schema->string()->format('date')->description('Inclusive start date (YYYY-MM-DD).'),
            'end'           => $schema->string()->format('date')->description('Inclusive end date (YYYY-MM-DD).'),
            'account_id'    => $schema->integer()->description('Filter to transactions touching this account id.'),
            'account_name'  => $schema
                ->string()
                ->description('Filter to transactions touching the account with this exact name; ignored if `account_id` is set.'),
            'type'          => $schema
                ->string()
                ->description('Transaction-type filter: "withdrawal", "deposit", "transfer", "all", or "default" (withdrawal+deposit+transfer).'),
            'page'          => $schema->integer()->description('Page number (1-based).'),
            'limit'         => $schema->integer()->description("Items per page; defaults to the user's listPageSize preference."),
            'verbose'       => $schema->boolean()->description('Return the raw JSON:API document instead of the reduced shape.'),
            'include_nulls' => $schema->boolean()->description('Preserve null-valued attributes in the reduced output.')
        ];
    }

    /**
     * @return array<int, string>
     */
    private function mapTypes(string $type): array
    {
        $map = [
            'all'             => [
                TransactionTypeEnum::WITHDRAWAL->value,
                TransactionTypeEnum::DEPOSIT->value,
                TransactionTypeEnum::TRANSFER->value,
                TransactionTypeEnum::OPENING_BALANCE->value,
                TransactionTypeEnum::RECONCILIATION->value
            ],
            'default'         => [
                TransactionTypeEnum::WITHDRAWAL->value,
                TransactionTypeEnum::DEPOSIT->value,
                TransactionTypeEnum::TRANSFER->value
            ],
            'withdrawal'      => [TransactionTypeEnum::WITHDRAWAL->value],
            'deposit'         => [TransactionTypeEnum::DEPOSIT->value],
            'transfer'        => [TransactionTypeEnum::TRANSFER->value],
            'opening_balance' => [TransactionTypeEnum::OPENING_BALANCE->value],
            'reconciliation'  => [TransactionTypeEnum::RECONCILIATION->value]
        ];
        $key = strtolower(trim($type));

        return $map[$key] ?? $map['default'];
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
