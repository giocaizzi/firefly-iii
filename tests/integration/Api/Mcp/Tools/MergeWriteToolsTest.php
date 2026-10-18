<?php

/*
 * MergeWriteToolsTest.php
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
use FireflyIII\Mcp\Tools\AccountsWriteTool;
use FireflyIII\Mcp\Tools\CategoriesWriteTool;
use FireflyIII\Mcp\Tools\TagsWriteTool;
use FireflyIII\Models\Account;
use FireflyIII\Models\Category;
use FireflyIII\Models\Tag;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Tests\integration\Api\Mcp\Concerns\CallsApiTools;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Write side of tags, categories and accounts (their read tools already exist), including merge.
 *
 * @internal
 *
 * @coversNothing
 */
final class MergeWriteToolsTest extends TestCase
{
    use CallsApiTools;
    use EnsuresPassportKeys;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\AccountsWriteTool
     */
    public function testAccountCreateUpdateAndMerge(): void
    {
        $user    = $this->createAuthenticatedUser();
        $created = $this->okJson($user, AccountsWriteTool::class, [
            'action' => 'create',
            'data'   => [
                'name'                 => 'Main',
                'type'                 => 'asset',
                'account_role'         => 'defaultAsset',
                'currency_code'        => 'EUR',
                'iban'                 => 'NL02ABNA0123456789',
                'opening_balance'      => '100',
                'opening_balance_date' => '2026-01-01'
            ]
        ]);
        $id = $created['data']['id'];
        self::assertEquals(
            ['name' => 'Main', 'type' => 'asset', 'iban' => 'NL02ABNA0123456789', 'current_balance' => '100'],
            array_intersect_key($created['data'], array_flip(['name', 'type', 'iban', 'current_balance']))
        );

        $updated = $this->okJson($user, AccountsWriteTool::class, [
            'action' => 'update',
            'id'     => $id,
            'data'   => ['name' => 'Main checking', 'active' => false]
        ]);
        self::assertSame(['name' => 'Main checking', 'active' => false], array_intersect_key($updated['data'], ['name' => 0, 'active' => 0]));
        $this->invalidArgument($user, AccountsWriteTool::class, ['action' => 'update', 'id' => $id, 'data' => ['iban' => 'not an iban']], 'data.iban');

        $old   = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop (old)');
        $new   = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop');
        $asset = $this->createAccount($user, AccountTypeEnum::ASSET, 'Wallet');
        $group = $this->createWithdrawal($user, $asset, $old, '5', 'Snack');

        $this->invalidArgument($user, AccountsWriteTool::class, ['action' => 'merge', 'id' => $old->id, 'into' => $asset->id], 'into');
        $this->invokeTool($user, AccountsWriteTool::class, [
            'action' => 'merge',
            'id'     => $old->id,
            'into'   => $new->id
        ])->assertHasNoErrors()->assertSee('1 transactions moved');
        self::assertFalse(Account::whereKey($old->id)->whereNull('deleted_at')->exists());
        self::assertTrue(DB::table('transactions')->where('transaction_journal_id', $this->journalId($group))->where('account_id', $new->id)->exists());
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\CategoriesWriteTool
     */
    public function testCategoryCreateRenameAndMerge(): void
    {
        $user    = $this->createAuthenticatedUser();
        $created = $this->okJson($user, CategoriesWriteTool::class, ['action' => 'create', 'data' => ['name' => 'Groceries']]);
        $id      = $created['data']['id'];
        $renamed = $this->okJson($user, CategoriesWriteTool::class, ['action' => 'update', 'id' => $id, 'data' => ['name' => 'Food']]);
        self::assertSame('Food', $renamed['data']['name']);
        $this->invalidArgument($user, CategoriesWriteTool::class, ['action' => 'create', 'data' => ['name' => 'Food']], 'data.name');
        $this->invalidArgument($user, CategoriesWriteTool::class, ['action' => 'delete', 'id' => $id], 'action');

        $group  = $this->journalWith($user, ['category_name' => 'Supermarket']);
        $source = Category::where('name', 'Supermarket')->firstOrFail();

        $this->invokeTool($user, CategoriesWriteTool::class, [
            'action' => 'merge',
            'id'     => $source->id,
            'into'   => $id
        ])->assertHasNoErrors()->assertSee('1 transactions moved');
        self::assertFalse(Category::whereKey($source->id)->exists());
        self::assertSame($id, (int) DB::table('category_transaction_journal')->where('transaction_journal_id', $this->journalId($group))->value('category_id'));
        $this->invokeTool($user, CategoriesWriteTool::class, ['action' => 'merge', 'id' => 999_999, 'into' => $id])->assertHasErrors(['not found']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\TagsWriteTool
     */
    public function testTagCreateRenameDeleteAndMerge(): void
    {
        $user    = $this->createAuthenticatedUser();
        $created = $this->okJson($user, TagsWriteTool::class, ['action' => 'create', 'data' => ['tag' => 'holiday', 'description' => 'trips']]);
        $id      = $created['data']['id'];
        self::assertSame('holiday', $created['data']['tag']);
        $this->invalidArgument($user, TagsWriteTool::class, ['action' => 'create', 'data' => ['tag' => 'holiday']], 'data.tag');

        $renamed = $this->okJson($user, TagsWriteTool::class, ['action' => 'update', 'id' => $id, 'data' => ['tag' => 'travel']]);
        self::assertSame('travel', $renamed['data']['tag']);

        // tag "trip" on two journals, "travel" on one of them: merging moves the other and drops the duplicate
        $first  = $this->journalWith($user, ['tags' => ['trip', 'travel']]);
        $second = $this->journalWith($user, ['tags' => ['trip']]);
        $trip   = Tag::where('tag', 'trip')->firstOrFail();

        $this->invalidArgument($user, TagsWriteTool::class, ['action' => 'merge', 'id' => 'trip', 'into' => 'trip'], 'into');
        $merged = $this->invokeTool($user, TagsWriteTool::class, ['action' => 'merge', 'id' => 'trip', 'into' => (string) $id]);
        $merged->assertHasNoErrors()->assertSee('2 transactions moved');
        self::assertFalse(Tag::whereKey($trip->id)->exists());
        self::assertEqualsCanonicalizing(
            [$this->journalId($first), $this->journalId($second)],
            DB::table('tag_transaction_journal')
                ->where('tag_id', $id)
                ->pluck('transaction_journal_id')
                ->map(static fn($v): int => (int) $v)
                ->all()
        );

        $error = $this->invalidArgument($user, TagsWriteTool::class, ['action' => 'delete', 'id' => 'travel'], 'confirm');
        self::assertSame(['transactions' => 2], $error['dependants']);
        $this->invokeTool($user, TagsWriteTool::class, ['action' => 'delete', 'id' => 'travel', 'confirm' => true])->assertHasNoErrors();
        self::assertFalse(Tag::whereKey($id)->exists());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }

    private function journalId(TransactionGroup $group): int
    {
        return (int) $group->transactionJournals()->firstOrFail()->id;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function journalWith(User $user, array $extra): TransactionGroup
    {
        $asset   = Account::where('name', 'Checking')->first() ?? $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $expense = Account::where('name', 'Shop')->first() ?? $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop');

        return $this->storeTransaction($user, [
            'type'           => TransactionTypeEnum::WITHDRAWAL->value,
            'date'           => Carbon::now(),
            'amount'         => '10',
            'description'    => 'Purchase',
            'source_id'      => $asset->id,
            'destination_id' => $expense->id,
            ...$extra
        ]);
    }
}
