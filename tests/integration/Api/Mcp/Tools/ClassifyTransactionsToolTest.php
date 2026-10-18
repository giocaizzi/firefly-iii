<?php

/*
 * ClassifyTransactionsToolTest.php
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
use FireflyIII\Mcp\Tools\ClassifyTransactionsTool;
use FireflyIII\Models\Account;
use FireflyIII\Models\Category;
use FireflyIII\Models\Note;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Tests\integration\Api\Mcp\Concerns\AssertsAuditLog;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers ClassifyTransactionsTool — dry-run preview, apply, targeting by query, the
 * classification-only argument whitelist, conflicts, create_missing and type restrictions.
 *
 * @internal
 *
 * @coversNothing
 */
final class ClassifyTransactionsToolTest extends TestCase
{
    use AssertsAuditLog;
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    private Account $checking;

    private Account $shop;

    private User $user;

    /**
     * @covers \FireflyIII\Mcp\Tools\ClassifyTransactionsTool
     */
    public function testGivenAlreadyClassifiedWhenClassifyingThenUnchanged(): void
    {
        $group = $this->withdrawal('Lunch', ['category_name' => 'Food']);

        $data = $this->decodeJson($this->invokeTool($this->user, ClassifyTransactionsTool::class, [
            'ids'           => [$group->id],
            'category_name' => 'Food',
            'dry_run'       => false
        ]))['data'];

        self::assertSame(0, $data['changed']);
        self::assertSame(1, $data['unchanged']);
        self::assertSame('unchanged', $data['rows'][0]['status']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ClassifyTransactionsTool
     */
    public function testGivenAmountArgumentWhenClassifyingThenRejected(): void
    {
        $group = $this->withdrawal('Lunch');

        $this->invokeTool($this->user, ClassifyTransactionsTool::class, [
            'ids'     => [$group->id],
            'amount'  => '1.00',
            'dry_run' => false
        ])->assertHasErrors(['invalid_argument', '"field":"amount"']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ClassifyTransactionsTool
     */
    public function testGivenBothIdsAndQueryWhenClassifyingThenRejected(): void
    {
        $this->invokeTool($this->user, ClassifyTransactionsTool::class, [
            'ids'           => [1],
            'query'         => 'x',
            'category_name' => 'Food'
        ])->assertHasErrors(['Pass either ids or query']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ClassifyTransactionsTool
     */
    public function testGivenClassifiedWhenClearingCategoryThenRemoved(): void
    {
        $group = $this->withdrawal('Lunch', ['category_name' => 'Food']);

        $this->invokeTool($this->user, ClassifyTransactionsTool::class, ['ids' => [$group->id], 'clear_category' => true, 'dry_run' => false])->assertOk();

        self::assertNull($this->journal($group)->categories()->first());
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ClassifyTransactionsTool
     */
    public function testGivenCreateMissingFalseWhenCategoryUnknownThenErrorsAndCreatesNothing(): void
    {
        $group = $this->withdrawal('Lunch');

        $this->invokeTool($this->user, ClassifyTransactionsTool::class, [
            'ids'            => [$group->id],
            'category_name'  => 'Unknown',
            'create_missing' => false,
            'dry_run'        => false
        ])->assertHasErrors(['missing_names', 'Unknown']);

        self::assertNull(Category::where('user_id', $this->user->id)->where('name', 'Unknown')->first());
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ClassifyTransactionsTool
     */
    public function testGivenDepositWhenSettingBudgetThenRowError(): void
    {
        $budget  = $this->createBudget($this->user);
        $revenue = $this->createAccount($this->user, AccountTypeEnum::REVENUE, 'Employer');
        $deposit = $this->storeTransaction($this->user, [
            'type'           => TransactionTypeEnum::DEPOSIT->value,
            'date'           => Carbon::now(),
            'amount'         => '100.00',
            'description'    => 'Salary',
            'source_id'      => $revenue->id,
            'destination_id' => $this->checking->id,
            'reconciled'     => false
        ]);

        $data = $this->decodeJson($this->invokeTool($this->user, ClassifyTransactionsTool::class, [
            'ids'       => [$deposit->id],
            'budget_id' => $budget->id,
            'dry_run'   => false
        ]))['data'];

        self::assertSame('error', $data['rows'][0]['status']);
        self::assertStringContainsString('withdrawals only', $data['rows'][0]['message']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ClassifyTransactionsTool
     */
    public function testGivenDryRunFalseWhenClassifyingThenAppliesAndReportsCreatedTags(): void
    {
        $this->createCategory($this->user, 'Food');
        $first  = $this->withdrawal('Lunch', ['tags' => ['old']]);
        $second = $this->withdrawal('Dinner', ['category_name' => 'Food']);
        $this->captureAuditChannel();

        $response = $this->invokeTool($this->user, ClassifyTransactionsTool::class, [
            'ids'           => [$first->id, $second->id, 999_999],
            'category_name' => 'Food',
            'add_tags'      => ['meal'],
            'remove_tags'   => ['old'],
            'append_notes'  => 'checked',
            'dry_run'       => false
        ]);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertFalse($payload['data']['dry_run']);
        self::assertSame(2, $payload['data']['changed']);
        self::assertSame(1, $payload['data']['not_found']);
        self::assertSame(['category' => 'Food', 'tags' => ['meal'], 'notes' => 'checked'], $payload['data']['rows'][0]['after']);
        self::assertSame('meal', $payload['meta']['created']['tags'][0]['tag']);

        foreach ([$first, $second] as $group) {
            $journal = $this->journal($group);
            self::assertSame('Food', $journal->categories()->first()?->name);
            self::assertSame(['meal'], $journal->tags()->pluck('tag')->all());
            self::assertSame('checked', $journal->notes()->first()?->text);
            self::assertSame(
                '12.5',
                rtrim(rtrim((string) $journal->transactions()->where('amount', '>', 0)->first()->amount, '0'), '.'),
                'Amount must not change.'
            );
        }
        $this->assertAuditInfo(static fn(string $message, array $ctx): bool => 'MCP write' === $message && ($ctx['transaction_id'] ?? null) === $first->id);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ClassifyTransactionsTool
     */
    public function testGivenExistingNotesWhenAppendingThenAddedOnNewLine(): void
    {
        $group = $this->withdrawal('Lunch', ['notes' => 'first']);

        $this->invokeTool($this->user, ClassifyTransactionsTool::class, ['ids' => [$group->id], 'append_notes' => 'second', 'dry_run' => false])->assertOk();

        self::assertSame("first\nsecond", Note::where('noteable_id', $this->journal($group)->id)->first()?->text);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ClassifyTransactionsTool
     */
    public function testGivenLiabilityNameWhenSettingDestinationThenRejected(): void
    {
        $group = $this->withdrawal('Lunch');
        $this->createAccount($this->user, AccountTypeEnum::LOAN, 'Car loan');

        $this->invokeTool($this->user, ClassifyTransactionsTool::class, [
            'ids'              => [$group->id],
            'destination_name' => 'Car loan'
        ])->assertHasErrors(['only expense account names']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ClassifyTransactionsTool
     */
    public function testGivenNewExpenseNameWhenClassifyingThenDryRunListsItAndApplyCreatesIt(): void
    {
        $group = $this->withdrawal('Lunch');
        $args  = ['ids' => [$group->id], 'destination_name' => 'Corner bistro'];

        $preview = $this->decodeJson($this->invokeTool($this->user, ClassifyTransactionsTool::class, $args));
        self::assertSame(['accounts' => ['Corner bistro']], $preview['meta']['would_create']);
        self::assertNull(Account::where('user_id', $this->user->id)->where('name', 'Corner bistro')->first());

        $applied = $this->decodeJson($this->invokeTool($this->user, ClassifyTransactionsTool::class, [...$args, 'dry_run' => false]));
        self::assertSame('Expense account', $applied['meta']['created']['accounts'][0]['type']);
        self::assertSame(
            'Corner bistro',
            $this
                ->journal($group)
                ->transactions()
                ->where('amount', '>', 0)
                ->first()
                ->account
                ->name
        );
        self::assertSame($this->checking->id, (int) $this->journal($group)->transactions()->where('amount', '<', 0)->first()->account_id);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ClassifyTransactionsTool
     */
    public function testGivenNoDryRunFlagWhenClassifyingThenPreviewsAndWritesNothing(): void
    {
        $this->createCategory($this->user, 'Food');
        $group = $this->withdrawal('Lunch');

        $response = $this->invokeTool($this->user, ClassifyTransactionsTool::class, ['ids' => [$group->id], 'category_name' => 'Food']);
        $response->assertOk();

        $data = $this->decodeJson($response)['data'];
        self::assertTrue($data['dry_run']);
        self::assertSame(1, $data['would_change']);
        self::assertSame(
            ['id' => $group->id, 'status' => 'would_change', 'before' => ['category' => null], 'after' => ['category' => 'Food']],
            $data['rows'][0]
        );
        self::assertNull($this->journal($group)->categories()->first());
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ClassifyTransactionsTool
     */
    public function testGivenQueryAboveLimitWhenClassifyingThenRejected(): void
    {
        $this->withdrawal('coffee one');
        $this->withdrawal('coffee two');

        $this->invokeTool($this->user, ClassifyTransactionsTool::class, [
            'query'         => 'coffee',
            'limit'         => 1,
            'category_name' => 'Food'
        ])->assertHasErrors(['invalid_argument', 'matches 2 transactions']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ClassifyTransactionsTool
     */
    public function testGivenQueryWhenClassifyingThenTargetsMatches(): void
    {
        $match = $this->withdrawal('groceries market');
        $this->withdrawal('rent');

        $data = $this->decodeJson($this->invokeTool($this->user, ClassifyTransactionsTool::class, ['query' => 'groceries', 'category_name' => 'Food']))['data'];

        self::assertSame(1, $data['matched']);
        self::assertSame($match->id, $data['rows'][0]['id']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ClassifyTransactionsTool
     */
    public function testGivenStaleTimestampWhenClassifyingThenConflictRowAndNoWrite(): void
    {
        $stale             = $this->withdrawal('Lunch');
        $fresh             = $this->withdrawal('Dinner');
        $stale->updated_at = Carbon::now()->subMinute();
        $stale->save();

        $data = $this->decodeJson($this->invokeTool($this->user, ClassifyTransactionsTool::class, [
            'ids'                 => [$stale->id, $fresh->id],
            'category_name'       => 'Food',
            'dry_run'             => false,
            'if_unmodified_since' => [
                (string) $stale->id => $stale->updated_at->copy()->subHour()->toAtomString(),
                (string) $fresh->id => $fresh->updated_at->toAtomString()
            ]
        ]))['data'];

        self::assertSame('conflict', $data['rows'][0]['status']);
        self::assertSame('changed', $data['rows'][1]['status']);
        self::assertSame(1, $data['conflict']);
        self::assertNull($this->journal($stale)->categories()->first());
        self::assertSame('Food', $this->journal($fresh)->categories()->first()?->name);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
        $this->user     = $this->createAuthenticatedUser();
        $this->checking = $this->createAccount($this->user, AccountTypeEnum::ASSET, 'Checking');
        $this->shop     = $this->createAccount($this->user, AccountTypeEnum::EXPENSE, 'Shop');
    }

    private function journal(TransactionGroup $group): TransactionJournal
    {
        return TransactionJournal::where('transaction_group_id', $group->id)->firstOrFail();
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function withdrawal(string $description, array $extra = []): TransactionGroup
    {
        return $this->storeTransaction($this->user, [
            'type'           => TransactionTypeEnum::WITHDRAWAL->value,
            'date'           => Carbon::now(),
            'amount'         => '12.50',
            'description'    => $description,
            'source_id'      => $this->checking->id,
            'destination_id' => $this->shop->id,
            'reconciled'     => false,
            ...$extra
        ]);
    }
}
