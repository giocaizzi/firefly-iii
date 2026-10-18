<?php

/*
 * TransactionAggregateTest.php
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

namespace Tests\integration\Api\Mcp\Tools;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Mcp\Tools\ListTransactionsTool;
use FireflyIII\Mcp\Tools\SearchTransactionsTool;
use FireflyIII\User;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers the `aggregate` option of search-transactions and list-transactions: totals over every
 * page, per-currency sums split by type, grouping, category filters, rows=false, and that calls
 * without the option are unchanged.
 *
 * @internal
 *
 * @coversNothing
 */
final class TransactionAggregateTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\SearchTransactionsTool
     */
    public function testGivenCategoryFiltersWhenAggregatingThenOnlyTheAggregateIsFiltered(): void
    {
        $user = $this->seedLedger();

        $excluded = $this->decodeJson($this->invokeTool($user, SearchTransactionsTool::class, ['query' => 'seeded', 'exclude_categories' => ['travel']]));
        self::assertCount(5, $excluded['data']);
        self::assertSame(4, $excluded['aggregate']['count']);
        self::assertSame('30.00', $excluded['aggregate']['totals'][0]['withdrawal']);

        $included = $this->decodeJson($this->invokeTool($user, SearchTransactionsTool::class, [
            'query'              => 'seeded',
            'include_categories' => ['Food', 'Travel'],
            'rows'               => false
        ]));
        self::assertSame(2, $included['aggregate']['count']);
        self::assertSame('40.00', $included['aggregate']['totals'][0]['withdrawal']);
        self::assertSame('-40.00', $included['aggregate']['totals'][0]['net']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\SearchTransactionsTool
     */
    public function testGivenGroupByCategoryAndTagWhenAggregatingThenNoneBucketCollectsTheRest(): void
    {
        $user = $this->seedLedger();

        $byCategory = $this->decodeJson($this->invokeTool($user, SearchTransactionsTool::class, [
            'query'    => 'seeded',
            'group_by' => 'category',
            'rows'     => false
        ]));
        $keys = array_column($byCategory['aggregate']['groups'], 'count', 'key');
        self::assertSame(['(none)' => 3, 'Food' => 1, 'Travel' => 1], $this->sorted($keys));
        $food = $this->groupByKey($byCategory['aggregate']['groups'], 'Food');
        self::assertIsInt($food['id']);

        $byTag = $this->decodeJson($this->invokeTool($user, SearchTransactionsTool::class, ['query' => 'seeded', 'group_by' => 'tag', 'rows' => false]));
        $tags  = array_column($byTag['aggregate']['groups'], 'count', 'key');
        self::assertSame(['(none)' => 3, 'holiday' => 1, 'work' => 2], $this->sorted($tags));
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\SearchTransactionsTool
     */
    public function testGivenGroupByMonthWhenAggregatingThenBucketsAreChronological(): void
    {
        $user = $this->seedLedger();

        $payload = $this->decodeJson($this->invokeTool($user, SearchTransactionsTool::class, ['query' => 'seeded', 'group_by' => 'month', 'rows' => false]));

        self::assertArrayNotHasKey('data', $payload);
        self::assertSame('month', $payload['aggregate']['group_by']);
        self::assertSame(['2026-01', '2026-02'], array_column($payload['aggregate']['groups'], 'key'));
        self::assertSame('40.00', $payload['aggregate']['groups'][0]['totals'][0]['withdrawal']);
        self::assertSame('20.00', $payload['aggregate']['groups'][1]['totals'][0]['withdrawal']);
        self::assertSame('EUR', $payload['meta']['primary_currency']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\SearchTransactionsTool
     */
    public function testGivenGroupByTypeAndAccountWhenAggregatingThenUsesOwnSide(): void
    {
        $user = $this->seedLedger();

        $byType = $this->decodeJson($this->invokeTool($user, SearchTransactionsTool::class, ['query' => 'seeded', 'group_by' => 'type', 'rows' => false]));
        self::assertSame(['deposit' => 1, 'transfer' => 1, 'withdrawal' => 3], $this->sorted(array_column($byType['aggregate']['groups'], 'count', 'key')));

        $byAccount = $this->decodeJson($this->invokeTool($user, SearchTransactionsTool::class, [
            'query'    => 'seeded',
            'group_by' => 'account',
            'rows'     => false
        ]));
        // the deposit lands on Checking, the withdrawals and the transfer leave it.
        self::assertSame(['Checking' => 5], array_column($byAccount['aggregate']['groups'], 'count', 'key'));

        $byDestination = $this->decodeJson($this->invokeTool($user, SearchTransactionsTool::class, [
            'query'    => 'seeded',
            'group_by' => 'destination',
            'rows'     => false
        ]));
        self::assertSame(['Checking' => 1, 'Savings' => 1, 'Shop' => 3], $this->sorted(array_column($byDestination['aggregate']['groups'], 'count', 'key')));
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\SearchTransactionsTool
     */
    public function testGivenInvalidAggregateArgsWhenSearchingThenRejects(): void
    {
        $user = $this->createAuthenticatedUser();

        $this->invokeTool($user, SearchTransactionsTool::class, ['query' => 'x', 'group_by' => 'week'])->assertHasErrors(['group_by must be one of']);
        $this->invokeTool($user, SearchTransactionsTool::class, [
            'query'              => 'x',
            'exclude_categories' => [['nested']]
        ])->assertHasErrors(['exclude_categories must be a list']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ListTransactionsTool
     */
    public function testGivenListWithAggregateWhenFilteredThenTotalsFollowTheFilters(): void
    {
        $user = $this->seedLedger();

        $all = $this->decodeJson($this->invokeTool($user, ListTransactionsTool::class, ['limit' => 2, 'aggregate' => true]));
        self::assertCount(2, $all['data']);
        self::assertSame(5, $all['aggregate']['count']);

        $january = $this->decodeJson($this->invokeTool($user, ListTransactionsTool::class, [
            'start'    => '2026-01-01',
            'end'      => '2026-01-31',
            'type'     => 'withdrawal',
            'group_by' => 'category',
            'rows'     => false
        ]));
        self::assertSame(2, $january['aggregate']['count']);
        self::assertSame(['Food' => 1, 'Travel' => 1], $this->sorted(array_column($january['aggregate']['groups'], 'count', 'key')));

        $plain = $this->decodeJson($this->invokeTool($user, ListTransactionsTool::class, []));
        self::assertArrayNotHasKey('aggregate', $plain);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\SearchTransactionsTool
     */
    public function testGivenNoAggregateArgsWhenSearchingThenOutputIsUnchanged(): void
    {
        $user = $this->seedLedger();

        $payload = $this->decodeJson($this->invokeTool($user, SearchTransactionsTool::class, ['query' => 'seeded']));
        self::assertArrayNotHasKey('aggregate', $payload);
        self::assertCount(5, $payload['data']);

        $verbose = $this->decodeJson($this->invokeTool($user, SearchTransactionsTool::class, ['query' => 'seeded', 'verbose' => true, 'aggregate' => true]));
        self::assertSame('transactions', $verbose['data'][0]['type']);
        self::assertSame(5, $verbose['aggregate']['count']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\SearchTransactionsTool
     */
    public function testGivenSearchWithAggregateWhenPagedThenTotalsCoverEveryPage(): void
    {
        $user = $this->seedLedger();

        $response = $this->invokeTool($user, SearchTransactionsTool::class, ['query' => 'seeded', 'limit' => 1, 'aggregate' => true]);
        $response->assertOk();
        $payload = $this->decodeJson($response);

        self::assertCount(1, $payload['data']);
        self::assertTrue($payload['meta']['has_more']);
        self::assertSame(5, $payload['aggregate']['count']);
        self::assertSame(
            [['currency' => 'EUR', 'count' => 5, 'withdrawal' => '60.00', 'deposit' => '1000.00', 'transfer' => '200.00', 'net' => '940.00']],
            $payload['aggregate']['totals']
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }

    /**
     * @param list<array<string, mixed>> $groups
     *
     * @return array<string, mixed>
     */
    private function groupByKey(array $groups, string $key): array
    {
        foreach ($groups as $group) {
            if ($key === $group['key']) {
                return $group;
            }
        }
        self::fail(sprintf('No group "%s".', $key));
    }

    /**
     * @param list<string> $tags
     *
     * @return array<string, mixed>
     */
    private function line(
        TransactionTypeEnum $type,
        string $date,
        string $amount,
        string $description,
        int $sourceId,
        int $destinationId,
        null|string $category,
        array $tags
    ): array {
        return [
            'type'           => $type->value,
            'date'           => Carbon::parse($date),
            'amount'         => $amount,
            'description'    => $description,
            'source_id'      => $sourceId,
            'destination_id' => $destinationId,
            'category_name'  => $category,
            'tags'           => $tags,
            'reconciled'     => false
        ];
    }

    /**
     * January: withdrawals 10 (Food, #work) and 30 (Travel, #work #holiday). February: deposit 1000,
     * transfer 200, withdrawal 20 (no category, no tags). All in EUR, all descriptions contain "seeded".
     */
    private function seedLedger(): User
    {
        $user     = $this->createAuthenticatedUser();
        $checking = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $savings  = $this->createAccount($user, AccountTypeEnum::ASSET, 'Savings');
        $shop     = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop');
        $employer = $this->createAccount($user, AccountTypeEnum::REVENUE, 'Employer');

        $this->storeTransaction($user, $this->line(
            TransactionTypeEnum::WITHDRAWAL,
            '2026-01-05',
            '10.00',
            'seeded lunch',
            $checking->id,
            $shop->id,
            'Food',
            ['work']
        ));
        $this->storeTransaction($user, $this->line(
            TransactionTypeEnum::WITHDRAWAL,
            '2026-01-20',
            '30.00',
            'seeded train',
            $checking->id,
            $shop->id,
            'Travel',
            ['work', 'holiday']
        ));
        $this->storeTransaction($user, $this->line(
            TransactionTypeEnum::DEPOSIT,
            '2026-02-01',
            '1000.00',
            'seeded salary',
            $employer->id,
            $checking->id,
            null,
            []
        ));
        $this->storeTransaction($user, $this->line(
            TransactionTypeEnum::TRANSFER,
            '2026-02-02',
            '200.00',
            'seeded saving',
            $checking->id,
            $savings->id,
            null,
            []
        ));
        $this->storeTransaction($user, $this->line(TransactionTypeEnum::WITHDRAWAL, '2026-02-10', '20.00', 'seeded snack', $checking->id, $shop->id, null, []));

        return $user;
    }

    /**
     * @param array<string, mixed> $map
     *
     * @return array<string, mixed>
     */
    private function sorted(array $map): array
    {
        ksort($map);

        return $map;
    }
}
