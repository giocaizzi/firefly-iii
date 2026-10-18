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
use FireflyIII\Mcp\Support\InvalidArgument;
use FireflyIII\Mcp\Tools\Concerns\AggregatesTransactions;
use FireflyIII\Mcp\Tools\Concerns\ProjectsTransactions;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\JsonApi\Enrichments\TransactionGroupEnrichment;
use FireflyIII\Transformers\TransactionGroupTransformer;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Resource\Collection as FractalCollection;

#[Description('List transactions, newest first, by date range, account and type.')]
#[IsReadOnly]
#[IsIdempotent]
final class ListTransactionsTool extends AbstractMcpTool
{
    use AggregatesTransactions;
    use ProjectsTransactions;

    private const array TYPES = ['default', 'all', 'withdrawal', 'deposit', 'transfer', 'opening_balance', 'reconciliation'];

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = auth()->user();
        [$page, $limit] = $this->pageAndLimit($request);
        $this->validateShapeArgs($request);
        $aggregateOptions = $this->aggregateOptions($request);

        $types = $this->mapTypes($this->enumArg($request, 'type', self::TYPES, 'default'));

        $start = $this->dateArg($request, 'start');
        $end   = $this->dateArg($request, 'end');
        if (null !== $start && null !== $end && $start->gt($end)) {
            InvalidArgument::fail('start', 'start must not be after end.');
        }

        $accountIds = [];
        $accountId  = $request->get('account_id');
        if (null !== $accountId) {
            $accountIds[] = $this->positiveIntArg($request, 'account_id', 0);
        }
        $accountName = $request->get('account_name');
        if (null === $accountId && is_string($accountName) && '' !== $accountName) {
            $accountRepository = app(AccountRepositoryInterface::class);
            $accountRepository->setUser($user);
            $found = $accountRepository->findByName($accountName, []);
            if (null === $found) {
                InvalidArgument::fail('account_name', sprintf('No account named "%s".', $accountName));
            }
            $accountIds[] = (int) $found->id;
        }
        $accounts = null;
        if ([] !== $accountIds) {
            $accountRepository = app(AccountRepositoryInterface::class);
            $accountRepository->setUser($user);
            $accounts = $accountRepository->getAccountsById($accountIds);
            if (0 === $accounts->count()) {
                InvalidArgument::fail('account_id', sprintf('Account #%d not found.', $accountIds[0]));
            }
        }

        $aggregate = null;
        if (null !== $aggregateOptions) {
            // unpaged run over every match; collector arrays only, no enrichment or transformer.
            $all       = $this->filteredCollector($user, $types, $start, $end, $accounts)->withAccountInformation()->withCategoryInformation();
            $aggregate = $this->buildAggregate($all->getGroups(), $aggregateOptions);
            if (false === $aggregateOptions['rows']) {
                return $this->respondWithAggregate(null, $aggregate, $request);
            }
        }

        $collector = $this->filteredCollector($user, $types, $start, $end, $accounts)->withAPIInformation()->setLimit($limit)->setPage($page);
        $paginator = $collector->getPaginatedGroups();

        $enrichment = new TransactionGroupEnrichment();
        $enrichment->setUser($user);
        $groups = $enrichment->enrich($paginator->getCollection());

        $transformer = app(TransactionGroupTransformer::class);
        $resource    = new FractalCollection($groups, $transformer, 'transactions');
        $resource->setPaginator(new IlluminatePaginatorAdapter($paginator));

        $document = $this->jsonApiManager()->createData($resource)->toArray();

        if (null !== $aggregate) {
            return $this->respondWithAggregate($document, $aggregate, $request);
        }

        return $this->respondJsonApi($document, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'start'        => $schema->string()->format('date')->description('From date, inclusive (YYYY-MM-DD).'),
            'end'          => $schema->string()->format('date')->description('To date, inclusive (YYYY-MM-DD).'),
            'account_id'   => $schema->integer()->description('Only transactions touching this account.'),
            'account_name' => $schema->string()->description('Exact account name; ignored when account_id is set.'),
            'type'         => $schema->string()->enum(self::TYPES)->description('default = withdrawal+deposit+transfer.'),
            ...$this->pagingSchema($schema),
            ...$this->transactionOutputSchema($schema),
            ...$this->aggregateSchema($schema)
        ];
    }

    /**
     * @param array<int, string> $types
     */
    private function filteredCollector(User $user, array $types, null|Carbon $start, null|Carbon $end, null|Collection $accounts): GroupCollectorInterface
    {
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($user)->setTypes($types);
        if (null !== $start || null !== $end) {
            $collector->setRange($start ?? Carbon::createFromTimestamp(0), $end ?? Carbon::now()->endOfDay());
        }
        if (null !== $accounts) {
            $collector->setAccounts($accounts);
        }

        return $collector;
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
}
