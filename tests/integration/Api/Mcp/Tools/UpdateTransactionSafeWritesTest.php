<?php

/*
 * UpdateTransactionSafeWritesTest.php
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
use FireflyIII\Mcp\Tools\GetTransactionTool;
use FireflyIII\Mcp\Tools\UpdateTransactionTool;
use FireflyIII\Models\Account;
use FireflyIII\Models\Category;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers the safe-write additions to UpdateTransactionTool: tag merging, explicit clearing,
 * create_missing / meta.created and if_unmodified_since, plus the unchanged legacy semantics.
 *
 * @internal
 *
 * @coversNothing
 */
final class UpdateTransactionSafeWritesTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\UpdateTransactionTool
     */
    public function testGivenClassifiedTransactionWhenClearingThenCategoryBudgetAndBillAreRemoved(): void
    {
        $user   = $this->createAuthenticatedUser();
        $budget = $this->createBudget($user);
        $bill   = $this->createBill($user);
        [, $group] = $this->seedWithdrawal(['category_name' => 'Food', 'budget_id' => $budget->id, 'bill_id' => $bill->id], $user);
        $journal = $this->journalOf($group);
        self::assertNotNull($journal->categories()->first());
        self::assertNotNull($journal->budgets()->first());
        self::assertSame($bill->id, (int) $journal->bill_id);

        $this->invokeTool($user, UpdateTransactionTool::class, [
            'transaction_id' => $group->id,
            'clear_category' => true,
            'clear_budget'   => true,
            'clear_bill'     => true
        ])->assertOk();

        $journal = $this->journalOf($group);
        self::assertNull($journal->categories()->first());
        self::assertNull($journal->budgets()->first());
        self::assertNull($journal->bill_id);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\UpdateTransactionTool
     */
    public function testGivenClearAndValueForSameRelationWhenUpdatingThenRejected(): void
    {
        [$user, $group] = $this->seedWithdrawal();

        $response = $this->invokeTool($user, UpdateTransactionTool::class, [
            'transaction_id' => $group->id,
            'clear_category' => true,
            'category_name'  => 'Food'
        ]);
        $response->assertHasErrors(['clear_category cannot be combined with category_name']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\UpdateTransactionTool
     */
    public function testGivenCreateMissingFalseWhenNamingUnknownCategoryThenErrorsAndCreatesNothing(): void
    {
        [$user, $group] = $this->seedWithdrawal();

        $response = $this->invokeTool($user, UpdateTransactionTool::class, [
            'transaction_id' => $group->id,
            'category_name'  => 'Brand new',
            'add_tags'       => ['fresh'],
            'create_missing' => false
        ]);
        $response->assertHasErrors(['missing_names', 'Brand new', 'fresh']);

        self::assertNull(Category::where('user_id', $user->id)->where('name', 'Brand new')->first());
        self::assertSame(0, $user->tags()->count());
        self::assertNull($this->journalOf($group)->categories()->first());
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\UpdateTransactionTool
     */
    public function testGivenExistingNamesWhenUpdatingThenNoCreatedMeta(): void
    {
        [$user, $group] = $this->seedWithdrawal();
        $this->createCategory($user, 'Food');

        $payload = $this->decodeJson($this->invokeTool($user, UpdateTransactionTool::class, ['transaction_id' => $group->id, 'category_name' => 'Food']));

        self::assertArrayNotHasKey('created', $payload['meta']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\UpdateTransactionTool
     */
    public function testGivenMalformedIfUnmodifiedSinceWhenUpdatingThenRejected(): void
    {
        [$user, $group] = $this->seedWithdrawal();

        $this->invokeTool($user, UpdateTransactionTool::class, [
            'transaction_id'      => $group->id,
            'if_unmodified_since' => ['x']
        ])->assertHasErrors(['invalid_argument', 'if_unmodified_since']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\UpdateTransactionTool
     */
    public function testGivenStaleIfUnmodifiedSinceWhenUpdatingThenConflictAndNoWrite(): void
    {
        [$user, $group] = $this->seedWithdrawal();
        $group->updated_at = Carbon::now()->subMinute();
        $group->save();
        $stale = $group->updated_at->copy()->subHour()->toAtomString();

        $response = $this->invokeTool($user, UpdateTransactionTool::class, [
            'transaction_id'      => $group->id,
            'description'         => 'Changed',
            'if_unmodified_since' => $stale
        ]);
        $response->assertHasErrors(['"error":"conflict"']);

        self::assertSame('Lunch', $this->journalOf($group)->description);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\UpdateTransactionTool
     */
    public function testGivenTaggedTransactionWhenAddingAndRemovingTagsThenOthersAreKept(): void
    {
        [$user, $group] = $this->seedWithdrawal(['tags' => ['alpha', 'beta']]);

        $this->invokeTool($user, UpdateTransactionTool::class, [
            'transaction_id' => $group->id,
            'add_tags'       => ['gamma', 'Alpha'],
            'remove_tags'    => ['BETA']
        ])->assertOk();

        self::assertSame(['alpha', 'gamma'], $this->tagsOf($group));
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\UpdateTransactionTool
     */
    public function testGivenTagsArgumentWhenUpdatingThenTagsAreStillReplaced(): void
    {
        [$user, $group] = $this->seedWithdrawal(['tags' => ['alpha', 'beta']]);

        $this->invokeTool($user, UpdateTransactionTool::class, ['transaction_id' => $group->id, 'tags' => ['gamma']])->assertOk();

        self::assertSame(['gamma'], $this->tagsOf($group));
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\UpdateTransactionTool
     */
    public function testGivenUnknownCategoryWhenUpdatingThenItIsCreatedAndReported(): void
    {
        [$user, $group] = $this->seedWithdrawal();

        $response = $this->invokeTool($user, UpdateTransactionTool::class, ['transaction_id' => $group->id, 'category_name' => 'Brand new']);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertSame('Brand new', $payload['meta']['created']['categories'][0]['name']);
        self::assertSame('Brand new', $payload['data']['category']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\GetTransactionTool
     * @covers \FireflyIII\Mcp\Tools\UpdateTransactionTool
     */
    public function testGivenUpdatedAtFromReadWhenUpdatingThenWriteSucceeds(): void
    {
        [$user, $group] = $this->seedWithdrawal();

        $plain = $this->decodeJson($this->invokeTool($user, GetTransactionTool::class, ['id' => $group->id]));
        self::assertArrayNotHasKey('updated_at', $plain['data'], 'updated_at must not bloat the default row.');

        $read = $this->decodeJson($this->invokeTool($user, GetTransactionTool::class, ['id' => $group->id, 'fields' => ['updated_at']]));
        self::assertArrayHasKey('updated_at', $read['data']);

        $this->invokeTool($user, UpdateTransactionTool::class, [
            'transaction_id'      => $group->id,
            'description'         => 'Changed',
            'if_unmodified_since' => $read['data']['updated_at']
        ])->assertOk();

        self::assertSame('Changed', $this->journalOf($group)->description);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }

    private function journalOf(TransactionGroup $group): TransactionJournal
    {
        return TransactionJournal::where('transaction_group_id', $group->id)->firstOrFail();
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array{0: User, 1: TransactionGroup}
     */
    private function seedWithdrawal(array $extra = [], null|User $user = null): array
    {
        $user ??= $this->createAuthenticatedUser();

        /** @var Account $source */
        $source = Account::where('user_id', $user->id)->where('name', 'Checking')->first() ?? $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $destination = $this->createAccount($user, AccountTypeEnum::EXPENSE, sprintf('Shop %d', random_int(1, 1_000_000)));
        $group       = $this->storeTransaction($user, [
            'type'           => TransactionTypeEnum::WITHDRAWAL->value,
            'date'           => Carbon::now(),
            'amount'         => '12.50',
            'description'    => 'Lunch',
            'source_id'      => $source->id,
            'destination_id' => $destination->id,
            'reconciled'     => false,
            ...$extra
        ]);

        return [$user, $group];
    }

    /**
     * @return list<string>
     */
    private function tagsOf(TransactionGroup $group): array
    {
        return $this->journalOf($group)->tags()->orderBy('tag')->pluck('tag')->all();
    }
}
