<?php

/*
 * WriteResultShapesTest.php
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
use FireflyIII\Mcp\Tools\ApplyRuleTool;
use FireflyIII\Mcp\Tools\BillsWriteTool;
use FireflyIII\Mcp\Tools\BulkCreateTransactionsTool;
use FireflyIII\Mcp\Tools\ConvertTransactionTool;
use FireflyIII\Mcp\Tools\CreateWithdrawalTool;
use FireflyIII\Mcp\Tools\DeleteAccountTool;
use FireflyIII\Mcp\Tools\DeleteCategoryTool;
use FireflyIII\Mcp\Tools\DeleteRuleTool;
use FireflyIII\Mcp\Tools\DeleteTransactionTool;
use FireflyIII\Mcp\Tools\RecurrencesWriteTool;
use FireflyIII\Models\Account;
use FireflyIII\Models\AuditLogEntry;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Tests\integration\Api\Mcp\Concerns\CallsApiTools;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Write results share one JSON vocabulary: replayed, would_create, missing_names, deleted,
 * created/reason; plus the defaults and audit behaviour the write tools document.
 *
 * @internal
 *
 * @coversNothing
 */
final class WriteResultShapesTest extends TestCase
{
    use CallsApiTools;
    use EnsuresPassportKeys;
    use SeedsFireflyData;

    private Account $checking;

    private Account $shop;

    private User $user;

    /**
     * @covers \FireflyIII\Mcp\Tools\Concerns\WritesTransactions
     */
    public function testSingleCreateReportsReplayed(): void
    {
        $args  = [
            'source_id'        => $this->checking->id,
            'destination_name' => 'Shop',
            'amount'           => '4.00',
            'date'             => '2026-05-17',
            'description'      => 'Milk',
            'idempotency_key'  => 'milk-1'
        ];
        $first = $this->okJson($this->user, CreateWithdrawalTool::class, $args);
        self::assertFalse($first['meta']['replayed']);

        $again = $this->okJson($this->user, CreateWithdrawalTool::class, $args);
        self::assertTrue($again['meta']['replayed']);
        self::assertSame($first['data']['id'], $again['data']['id']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\BulkCreateTransactionsTool
     */
    public function testBulkDryRunListsNewNamesAndMissingNamesUseTheSingleCreateShape(): void
    {
        $items = [
            $this->item(['category_name' => 'Snacks']),
            $this->item([]),
            $this->item(['category_name' => 'Snacks', 'tags' => ['road trip']])
        ];

        $dryRun = $this->okJson($this->user, BulkCreateTransactionsTool::class, ['dry_run' => true, 'transactions' => $items]);
        self::assertSame(['categories' => ['Snacks'], 'tags' => ['road trip']], $dryRun['meta']['would_create']);
        self::assertSame(3, $dryRun['data']['would_create']);

        $response = $this->invokeTool($this->user, BulkCreateTransactionsTool::class, ['create_missing' => false, 'transactions' => $items]);
        $response->assertHasErrors();
        $error = json_decode($this->responseText($response), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('missing_names', $error['error']);
        self::assertSame(['categories' => ['Snacks'], 'tags' => ['road trip']], $error['missing']);
        self::assertSame([0, 2], $error['items']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\DeleteTransactionTool
     * @covers \FireflyIII\Mcp\Tools\DeleteRuleTool
     * @covers \FireflyIII\Mcp\Tools\DeleteCategoryTool
     * @covers \FireflyIII\Mcp\Tools\DeleteAccountTool
     */
    public function testLegacyDeletesReturnJson(): void
    {
        $group = $this->createWithdrawal($this->user, $this->checking, $this->shop);
        self::assertSame(['deleted' => 'transaction', 'id' => $group->id], $this->okJson($this->user, DeleteTransactionTool::class, ['transaction_id' => $group->id]));

        $rule = $this->createRule($this->user);
        self::assertSame(['deleted' => 'rule', 'id' => $rule->id], $this->okJson($this->user, DeleteRuleTool::class, ['rule_id' => $rule->id]));

        $category = $this->createCategory($this->user);
        self::assertSame(['deleted' => 'category', 'id' => $category->id], $this->okJson($this->user, DeleteCategoryTool::class, ['category_id' => $category->id]));

        $old = $this->createAccount($this->user, AccountTypeEnum::EXPENSE, 'Old shop');
        $this->createWithdrawal($this->user, $this->checking, $old);
        self::assertSame(
            ['deleted' => 'account', 'id' => $old->id, 'moved_to' => $this->shop->id],
            $this->okJson($this->user, DeleteAccountTool::class, ['account_id' => $old->id, 'move_to_account_id' => $this->shop->id])
        );
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\RecurrencesWriteTool
     */
    public function testRecurrenceTriggerThatCreatesNothingSaysWhy(): void
    {
        $first   = now()->addDays(10)->format('Y-m-d');
        $created = $this->okJson($this->user, RecurrencesWriteTool::class, [
            'action' => 'create',
            'data'   => [
                'type'         => 'withdrawal',
                'title'        => 'Gym',
                'first_date'   => $first,
                'repeat_until' => now()->addYear()->format('Y-m-d'),
                'repetitions'  => [['type' => 'daily', 'moment' => '']],
                'transactions' => [['description' => 'Gym', 'amount' => '30', 'source_id' => $this->checking->id, 'destination_id' => $this->shop->id]]
            ]
        ]);

        $result = $this->okJson($this->user, RecurrencesWriteTool::class, ['action' => 'trigger', 'id' => $created['data']['id'], 'date' => now()->format('Y-m-d')]);
        self::assertSame(['created' => 0, 'reason' => sprintf('date is before first_date (%s)', $first)], $result);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\BillsWriteTool
     */
    public function testNewSubscriptionsAreActiveAndUpdatesKeepActive(): void
    {
        $created = $this->okJson($this->user, BillsWriteTool::class, [
            'action' => 'create',
            'data'   => ['name' => 'Streaming', 'amount_min' => '9', 'amount_max' => '12', 'date' => '2026-01-05', 'repeat_freq' => 'monthly']
        ]);
        self::assertTrue($created['data']['active']);

        $id = $created['data']['id'];
        $this->okJson($this->user, BillsWriteTool::class, ['action' => 'update', 'id' => $id, 'data' => ['active' => false]]);
        $renamed = $this->okJson($this->user, BillsWriteTool::class, ['action' => 'update', 'id' => $id, 'data' => ['name' => 'Video']]);
        self::assertSame('Video', $renamed['data']['name']);
        self::assertFalse($renamed['data']['active']);
    }

    /**
     * get-audit-log only shows rule changes: a conversion writes no entry, a rule action does.
     *
     * @covers \FireflyIII\Mcp\Tools\ConvertTransactionTool
     */
    public function testConversionWritesNoAuditEntry(): void
    {
        $group   = $this->createWithdrawal($this->user, $this->checking, $this->shop, '50.00', 'coffee beans');
        $savings = $this->createAccount($this->user, AccountTypeEnum::ASSET, 'Savings');
        $this->okJson($this->user, ConvertTransactionTool::class, ['transaction_id' => $group->id, 'type' => 'transfer', 'destination_id' => $savings->id]);
        self::assertSame(0, $this->auditEntries($group));

        $this->okJson($this->user, ApplyRuleTool::class, ['rule_id' => $this->createRule($this->user)->id, 'transaction_ids' => [$group->id]]);
        self::assertGreaterThan(0, $this->auditEntries($group));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
        $this->user     = $this->createAuthenticatedUser();
        $this->checking = $this->createAccount($this->user, AccountTypeEnum::ASSET, 'Checking');
        $this->shop     = $this->createAccount($this->user, AccountTypeEnum::EXPENSE, 'Shop');
    }

    private function auditEntries(TransactionGroup $group): int
    {
        return AuditLogEntry::query()
            ->where('auditable_type', TransactionJournal::class)
            ->whereIn('auditable_id', $group->transactionJournals()->pluck('id'))
            ->count()
        ;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function item(array $extra): array
    {
        return [
            'type'           => 'withdrawal',
            'source_id'      => $this->checking->id,
            'destination_id' => $this->shop->id,
            'amount'         => '3.00',
            'date'           => '2026-03-01',
            'description'    => 'Snack',
            ...$extra
        ];
    }
}
