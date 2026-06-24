<?php

/*
 * ConvertTransactionToolTest.php
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
use FireflyIII\Mcp\Tools\ConvertTransactionTool;
use FireflyIII\Models\TransactionJournal;
use Tests\integration\Api\Mcp\Concerns\AssertsAuditLog;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers ConvertTransactionTool — withdrawal→transfer conversion and the error paths.
 *
 * @internal
 *
 * @coversNothing
 */
final class ConvertTransactionToolTest extends TestCase
{
    use AssertsAuditLog;
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\ConvertTransactionTool
     */
    public function testGivenWithdrawalWhenConvertingToTransferThenTypeChangedAndAuditLogged(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $expense     = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Groceries');
        $savings     = $this->createAccount($user, AccountTypeEnum::ASSET, 'Savings');
        $group       = $this->createWithdrawal($user, $source, $expense, '50.00', 'Top-up');
        $journalId   = (int) $group->transactionJournals()->first()->id;

        $this->captureAuditChannel();

        $response = $this->invokeTool($user, ConvertTransactionTool::class, [
            'transaction_id' => $group->id,
            'type'           => 'transfer',
            'destination_id' => $savings->id
        ]);
        $response->assertOk();

        $journal = TransactionJournal::find($journalId);
        self::assertSame('Transfer', $journal?->transactionType->type);

        $this->assertAuditInfo(
            static fn(string $message, array $ctx): bool => (
                'MCP write' === $message
                && ($ctx['transaction_id'] ?? null) === $group->id
                && 'transfer' === ($ctx['converted_to'] ?? null)
            )
        );
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ConvertTransactionTool
     */
    public function testGivenSameTypeWhenConvertingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $source   = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $expense  = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Groceries');
        $group    = $this->createWithdrawal($user, $source, $expense, '10.00', 'Lunch');
        $response = $this->invokeTool($user, ConvertTransactionTool::class, [
            'transaction_id' => $group->id,
            'type'           => 'withdrawal'
        ]);
        $response->assertHasErrors(['already a withdrawal']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ConvertTransactionTool
     */
    public function testGivenInvalidTypeWhenConvertingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $source   = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $expense  = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Groceries');
        $group    = $this->createWithdrawal($user, $source, $expense, '10.00', 'Lunch');
        $response = $this->invokeTool($user, ConvertTransactionTool::class, [
            'transaction_id' => $group->id,
            'type'           => 'reconciliation'
        ]);
        $response->assertHasErrors(['type is required and must be one of']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ConvertTransactionTool
     */
    public function testGivenMissingTransactionWhenConvertingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, ConvertTransactionTool::class, [
            'transaction_id' => 999_999,
            'type'           => 'transfer'
        ]);
        $response->assertHasErrors(['not found']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
