<?php

/*
 * CompactTransactionOutputTest.php
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
use FireflyIII\Mcp\Tools\BulkCreateTransactionsTool;
use FireflyIII\Mcp\Tools\CreateWithdrawalTool;
use FireflyIII\Mcp\Tools\GetTransactionTool;
use FireflyIII\Mcp\Tools\ListTransactionsTool;
use FireflyIII\Mcp\Tools\SearchTransactionsTool;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\User;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers the compact transaction projection (TransactionProjector via ProjectsTransactions):
 * the default row shape, split groups, `fields`, `description_max`, `include_nulls`, paging
 * hints, search warnings, write echoes, and the size budget of a compact row.
 *
 * @internal
 *
 * @coversNothing
 */
final class CompactTransactionOutputTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * A compact single-split row stays well under this many JSON characters; the full reduced
     * row is ~2.5k. Guards against the projection silently growing back.
     */
    private const int MAX_ROW_CHARS = 400;

    /**
     * @covers \FireflyIII\Mcp\Tools\BulkCreateTransactionsTool
     */
    public function testGivenBulkCreateWhenEchoingThenReturnsCompactItemsAndVerboseKeepsDocuments(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->enablePrimaryCurrency($user);
        $source = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $item   = [
            'type'             => 'withdrawal',
            'source_id'        => $source->id,
            'destination_name' => 'Bakery',
            'amount'           => '1.00',
            'date'             => '2026-05-17',
            'description'      => 'Bun'
        ];

        $compact = $this->decodeJson($this->invokeTool($user, BulkCreateTransactionsTool::class, ['transactions' => [$item]]));
        self::assertSame('Bun', $compact['data']['transactions'][0]['transaction']['description']);
        self::assertSame('EUR', $compact['meta']['primary_currency']);

        $verbose = $this->decodeJson($this->invokeTool($user, BulkCreateTransactionsTool::class, [
            'transactions' => [[...$item, 'description' => 'Roll']],
            'verbose'      => true
        ]));
        self::assertSame('transactions', $verbose['data']['transactions'][0]['document']['data']['type']);
    }

    /**
     * @covers \FireflyIII\Mcp\Support\JsonApiReducer
     */
    public function testGivenCompactFalseWhenListingThenNestedNullsAreOmitted(): void
    {
        [$user] = $this->seedWithdrawal('Lunch', '12.50');

        $payload = $this->decodeJson($this->invokeTool($user, ListTransactionsTool::class, ['compact' => false]));
        $split   = $payload['data'][0]['transactions'][0];

        self::assertSame('Lunch', $split['description']);
        self::assertNotContains(null, $split, 'Reduced split rows must not leak null attributes.');

        $withNulls = $this->decodeJson($this->invokeTool($user, ListTransactionsTool::class, ['compact' => false, 'include_nulls' => true]));
        self::assertContains(null, $withNulls['data'][0]['transactions'][0]);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\CreateWithdrawalTool
     */
    public function testGivenCreatedWithdrawalWhenEchoingThenReturnsCompactRow(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->enablePrimaryCurrency($user);
        $source = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');

        $payload = $this->decodeJson($this->invokeTool($user, CreateWithdrawalTool::class, [
            'source_id'        => $source->id,
            'destination_name' => 'Bakery',
            'amount'           => '3.40',
            'date'             => '2026-05-17T08:30:00',
            'description'      => 'Croissant'
        ]));

        self::assertSame('3.4', $payload['data']['amount']);
        self::assertSame('2026-05-17 08:30', $payload['data']['date']);
        self::assertSame('Bakery', $payload['data']['destination']);
        self::assertArrayNotHasKey('transactions', $payload['data']);
    }

    /**
     * @covers \FireflyIII\Mcp\Support\TransactionProjector
     */
    public function testGivenFieldsWhenListingThenReturnsOnlyThoseKeysPlusId(): void
    {
        [$user, $source] = $this->seedWithdrawal('Lunch', '12.50');

        $payload = $this->decodeJson($this->invokeTool($user, ListTransactionsTool::class, ['fields' => ['amount', 'source_id']]));

        self::assertSame(['id', 'amount', 'source_id'], array_keys($payload['data'][0]));
        self::assertSame((string) $source->id, $payload['data'][0]['source_id']);
    }

    /**
     * @covers \FireflyIII\Mcp\Support\TransactionProjector
     */
    public function testGivenIncludeNullsWhenListingThenKeepsEmptyCompactKeys(): void
    {
        [$user] = $this->seedWithdrawal('Lunch', '12.50');

        $payload = $this->decodeJson($this->invokeTool($user, ListTransactionsTool::class, ['include_nulls' => true]));

        self::assertArrayHasKey('budget', $payload['data'][0]);
        self::assertNull($payload['data'][0]['budget']);
    }

    /**
     * Row `id` is the group id every tool accepts; `journal_id` appears only when it differs.
     *
     * @covers \FireflyIII\Mcp\Support\TransactionProjector
     */
    public function testGivenJournalIdDiffersFromGroupIdWhenListingThenIdIsGroupIdAndJournalIdIsAdded(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->enablePrimaryCurrency($user);
        $source = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $shop   = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop');
        // a two-split group first, so the next group's id and journal id drift apart
        app(TransactionGroupRepositoryInterface::class)->store([
            'user'         => $user,
            'user_group'   => $user->userGroup,
            'group_title'  => 'Split',
            'transactions' => [$this->line($source, $shop, '1.00', 'One'), $this->line($source, $shop, '2.00', 'Two')]
        ]);
        $single  = $this->storeTransaction($user, $this->line($source, $shop, '3.00', 'Single'));
        $journal = (int) $single->transactionJournals()->firstOrFail()->id;
        self::assertNotSame((int) $single->id, $journal);

        $payload = $this->decodeJson($this->invokeTool($user, ListTransactionsTool::class, ['type' => 'withdrawal']));
        $rows    = array_values(array_filter($payload['data'], static fn(array $row): bool => 'Single' === ($row['description'] ?? null)));

        self::assertSame((int) $single->id, $rows[0]['id']);
        self::assertSame($journal, $rows[0]['journal_id']);
        self::assertSame(['id', 'journal_id'], array_slice(array_keys($rows[0]), 0, 2));

        // the row id round-trips into get-transaction
        $fetched = $this->decodeJson($this->invokeTool($user, GetTransactionTool::class, ['id' => $rows[0]['id']]));
        self::assertSame('Single', $fetched['data']['description']);
    }

    /**
     * @covers \FireflyIII\Mcp\Support\TransactionProjector
     */
    public function testGivenLongDescriptionWhenListingThenTruncatesUnlessCapDisabled(): void
    {
        $long = str_repeat('abcdefghij', 20);
        [$user] = $this->seedWithdrawal($long, '1.00');

        $capped = $this->decodeJson($this->invokeTool($user, ListTransactionsTool::class, ['description_max' => 10]));
        self::assertSame('abcdefghi…', $capped['data'][0]['description']);

        $default = $this->decodeJson($this->invokeTool($user, ListTransactionsTool::class, []));
        self::assertSame(120, mb_strlen($default['data'][0]['description']));

        $full = $this->decodeJson($this->invokeTool($user, ListTransactionsTool::class, ['description_max' => 0]));
        self::assertSame($long, $full['data'][0]['description']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\SearchTransactionsTool
     */
    public function testGivenMatchWhenSearchingThenReturnsCompactRows(): void
    {
        [$user] = $this->seedWithdrawal('Lunch', '12.50');

        $payload = $this->decodeJson($this->invokeTool($user, SearchTransactionsTool::class, ['query' => 'lunch']));

        self::assertCount(1, $payload['data']);
        self::assertSame('Lunch', $payload['data'][0]['description']);
        self::assertSame('12.5', $payload['data'][0]['amount']);
        self::assertArrayNotHasKey('warnings', $payload['meta']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\AbstractMcpTool
     */
    public function testGivenMorePagesWhenListingThenMetaHasNextPage(): void
    {
        [$user, $source, $destination] = $this->seedWithdrawal('First', '1.00');
        $this->createWithdrawal($user, $source, $destination, '2.00', 'Second');

        $first = $this->decodeJson($this->invokeTool($user, ListTransactionsTool::class, ['limit' => 1]));
        self::assertTrue($first['meta']['has_more']);
        self::assertSame(2, $first['meta']['next_page']);
        self::assertSame(2, $first['meta']['pagination']['total']);

        $last = $this->decodeJson($this->invokeTool($user, ListTransactionsTool::class, ['limit' => 1, 'page' => 2]));
        self::assertFalse($last['meta']['has_more']);
        self::assertArrayNotHasKey('next_page', $last['meta']);
    }

    /**
     * @covers \FireflyIII\Mcp\Support\TransactionProjector
     */
    public function testGivenSplitGroupWhenGettingThenKeepsSplitsAsList(): void
    {
        $user   = $this->createAuthenticatedUser();
        $source = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $shop   = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop');
        $group  = app(TransactionGroupRepositoryInterface::class)->store([
            'user'         => $user,
            'user_group'   => $user->userGroup,
            'group_title'  => 'Weekly shop',
            'transactions' => [
                $this->line($source, $shop, '10.00', 'Bread'),
                $this->line($source, $shop, '5.25', 'Milk')
            ]
        ]);

        $payload = $this->decodeJson($this->invokeTool($user, GetTransactionTool::class, ['id' => $group->id]));

        self::assertSame((int) $group->id, $payload['data']['group_id']);
        self::assertSame('Weekly shop', $payload['data']['title']);
        self::assertCount(2, $payload['data']['splits']);
        $amounts = array_column($payload['data']['splits'], 'amount');
        sort($amounts, SORT_STRING);
        self::assertSame(['10', '5.25'], $amounts);
        self::assertArrayNotHasKey('group_id', $payload['data']['splits'][0]);
        $journalIds = $group
            ->transactionJournals()
            ->pluck('id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
        $splitIds   = array_column($payload['data']['splits'], 'journal_id');
        sort($splitIds);
        self::assertSame($journalIds, $splitIds);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\SearchTransactionsTool
     */
    public function testGivenUnknownOperatorWhenSearchingThenReportsWarning(): void
    {
        [$user] = $this->seedWithdrawal('Lunch', '12.50');

        $payload = $this->decodeJson($this->invokeTool($user, SearchTransactionsTool::class, ['query' => 'lunch bogus_operator:xyz']));

        self::assertArrayHasKey('warnings', $payload['meta']);
        self::assertStringContainsString('bogus_operator', $payload['meta']['warnings'][0]);
        self::assertStringContainsString('firefly-iii://help/search', $payload['meta']['warnings'][0]);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\AbstractMcpTool
     */
    public function testGivenVerboseWhenListingThenReturnsUntouchedJsonApiDocument(): void
    {
        [$user] = $this->seedWithdrawal('Lunch', '12.50');

        $payload = $this->decodeJson($this->invokeTool($user, ListTransactionsTool::class, ['verbose' => true]));

        self::assertSame('transactions', $payload['data'][0]['type']);
        self::assertSame('12.500000000000', $payload['data'][0]['attributes']['transactions'][0]['amount']);
        self::assertArrayNotHasKey('has_more', $payload['meta']);
        self::assertArrayNotHasKey('primary_currency', $payload['meta']);
    }

    /**
     * @covers \FireflyIII\Mcp\Support\TransactionProjector
     * @covers \FireflyIII\Mcp\Tools\ListTransactionsTool
     */
    public function testGivenWithdrawalWhenListingThenReturnsFlatCompactRow(): void
    {
        [$user] = $this->seedWithdrawal('Lunch', '12.50');

        $payload = $this->decodeJson($this->invokeTool($user, ListTransactionsTool::class, []));
        $row     = $payload['data'][0];

        self::assertSame(['id', 'date', 'type', 'amount', 'description', 'source', 'destination', 'category', 'tags'], array_keys($row));
        self::assertSame('12.5', $row['amount']);
        self::assertSame('withdrawal', $row['type']);
        self::assertSame('2026-01-15', $row['date']);
        self::assertSame('Checking', $row['source']);
        self::assertSame('Groceries', $row['destination']);
        self::assertSame('Food', $row['category']);
        self::assertSame(['weekly'], $row['tags']);
        self::assertArrayNotHasKey('currency', $row, 'Primary-currency rows carry no currency code.');
        self::assertSame('EUR', $payload['meta']['primary_currency']);
        self::assertLessThan(self::MAX_ROW_CHARS, strlen((string) json_encode($row)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }

    private function enablePrimaryCurrency(User $user): void
    {
        $user->userGroup->currencies()->sync([$this->primaryCurrency()->id => ['group_default' => true]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(Account $source, Account $destination, string $amount, string $description): array
    {
        return [
            'type'           => TransactionTypeEnum::WITHDRAWAL->value,
            'date'           => Carbon::parse('2026-01-15'),
            'amount'         => $amount,
            'description'    => $description,
            'source_id'      => $source->id,
            'destination_id' => $destination->id,
            'reconciled'     => false
        ];
    }

    /**
     * @return array{0: User, 1: Account, 2: Account, 3: TransactionGroup}
     */
    private function seedWithdrawal(string $description, string $amount): array
    {
        $user = $this->createAuthenticatedUser();
        $this->enablePrimaryCurrency($user);
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $destination = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Groceries');
        $category    = $this->createCategory($user, 'Food');
        $group       = $this->storeTransaction($user, [
            ...$this->line($source, $destination, $amount, $description),
            'category_id' => $category->id,
            'tags'        => ['weekly']
        ]);

        return [$user, $source, $destination, $group];
    }
}
