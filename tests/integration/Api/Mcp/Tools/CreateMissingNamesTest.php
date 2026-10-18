<?php

/*
 * CreateMissingNamesTest.php
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

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Mcp\Tools\BulkCreateTransactionsTool;
use FireflyIII\Mcp\Tools\CreateDepositTool;
use FireflyIII\Mcp\Tools\CreateWithdrawalTool;
use FireflyIII\Models\Account;
use FireflyIII\Models\Category;
use FireflyIII\Models\TransactionJournal;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers `create_missing` and `meta.created` on the create tools: the default still creates
 * unknown names (now reported), `create_missing=false` errors before anything is stored.
 *
 * @internal
 *
 * @coversNothing
 */
final class CreateMissingNamesTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\BulkCreateTransactionsTool
     */
    public function testGivenBulkWithUnknownCategoryWhenCreateMissingFalseThenItemRejected(): void
    {
        $user   = $this->createAuthenticatedUser();
        $source = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $shop   = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop');

        $response = $this->invokeTool($user, BulkCreateTransactionsTool::class, [
            'create_missing' => false,
            'transactions'   => [
                $this->bulkItem($source, $shop, ['category_name' => 'Nope'])
            ]
        ]);
        $response->assertHasErrors(['missing_names', 'Nope']);
        self::assertNull(Category::where('user_id', $user->id)->where('name', 'Nope')->first());
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\BulkCreateTransactionsTool
     */
    public function testGivenBulkWithUnknownTagWhenCreatingThenMetaCreatedListsIt(): void
    {
        $user   = $this->createAuthenticatedUser();
        $source = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $shop   = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop');

        $payload = $this->decodeJson($this->invokeTool($user, BulkCreateTransactionsTool::class, [
            'transactions' => [$this->bulkItem($source, $shop, ['tags' => ['batch-tag']]), $this->bulkItem($source, $shop)]
        ]));

        self::assertSame(2, $payload['data']['created']);
        self::assertSame([['id' => $user->tags()->first()->id, 'tag' => 'batch-tag']], $payload['meta']['created']['tags']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\CreateDepositTool
     */
    public function testGivenCreateMissingFalseWhenRevenueUnknownThenErrorsAndStoresNothing(): void
    {
        $user        = $this->createAuthenticatedUser();
        $destination = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $journals    = TransactionJournal::count();

        $response = $this->invokeTool($user, CreateDepositTool::class, [
            'source_name'    => 'Unknown employer',
            'destination_id' => $destination->id,
            'amount'         => '100.00',
            'date'           => '2026-03-01',
            'description'    => 'Salary',
            'create_missing' => false
        ]);
        $response->assertHasErrors(['missing_names', 'Unknown employer']);

        self::assertSame($journals, TransactionJournal::count());
        self::assertNull(Account::where('user_id', $user->id)->where('name', 'Unknown employer')->first());
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\CreateWithdrawalTool
     */
    public function testGivenExistingNamesWhenCreatingWithdrawalThenNothingIsReportedAsCreated(): void
    {
        $user   = $this->createAuthenticatedUser();
        $source = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop');
        $this->createCategory($user, 'Food');

        $payload = $this->decodeJson($this->invokeTool($user, CreateWithdrawalTool::class, [
            'source_id'        => $source->id,
            'destination_name' => 'Shop',
            'amount'           => '5.00',
            'date'             => '2026-03-01',
            'description'      => 'Coffee',
            'category_name'    => 'Food'
        ]));

        self::assertArrayNotHasKey('created', $payload['meta']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\CreateWithdrawalTool
     */
    public function testGivenUnknownNamesWhenCreatingWithdrawalThenTheyAreCreatedAndReported(): void
    {
        $user   = $this->createAuthenticatedUser();
        $source = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');

        $response = $this->invokeTool($user, CreateWithdrawalTool::class, [
            'source_id'        => $source->id,
            'destination_name' => 'New shop',
            'amount'           => '5.00',
            'date'             => '2026-03-01',
            'description'      => 'Coffee',
            'category_name'    => 'New category',
            'tags'             => ['new-tag']
        ]);
        $response->assertOk();

        $created = $this->decodeJson($response)['meta']['created'];
        self::assertSame('New shop', $created['accounts'][0]['name']);
        self::assertSame('Expense account', $created['accounts'][0]['type']);
        self::assertSame('New category', $created['categories'][0]['name']);
        self::assertSame('new-tag', $created['tags'][0]['tag']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function bulkItem(Account $source, Account $destination, array $extra = []): array
    {
        return [
            'type'           => 'withdrawal',
            'source_id'      => $source->id,
            'destination_id' => $destination->id,
            'amount'         => '3.00',
            'date'           => '2026-03-01',
            'description'    => 'Item',
            ...$extra
        ];
    }
}
