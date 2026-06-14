<?php

/*
 * DeleteTransactionToolTest.php
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
use FireflyIII\Mcp\Tools\DeleteTransactionTool;
use FireflyIII\Models\TransactionJournal;
use Tests\integration\Api\Mcp\Concerns\AssertsAuditLog;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers DeleteTransactionTool — successful soft-delete + audit log line,
 * and the missing-transaction error path.
 *
 * @internal
 *
 * @coversNothing
 */
final class DeleteTransactionToolTest extends TestCase
{
    use AssertsAuditLog;
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\DeleteTransactionTool
     */
    public function testGivenExistingTransactionWhenDeletingThenJournalSoftDeletedAndAuditLogged(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $destination = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Groceries');
        $group       = $this->createWithdrawal($user, $source, $destination, '20.00', 'Test');
        $journalId   = (int) $group->transactionJournals()->first()->id;

        $this->captureAuditChannel();

        $response = $this->invokeTool($user, DeleteTransactionTool::class, ['transaction_id' => $group->id]);
        $response->assertOk();

        self::assertNotNull(TransactionJournal::withTrashed()->find($journalId)?->deleted_at, 'Journal should be soft-deleted.');
        $this->assertAuditInfo(
            static fn(string $message, array $ctx): bool => (
                'MCP write' === $message
                && ($ctx['transaction_id'] ?? null) === $group->id
                && false === ($ctx['idempotent_replay'] ?? null)
            )
        );
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\DeleteTransactionTool
     */
    public function testGivenInvalidIdWhenDeletingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, DeleteTransactionTool::class, []);
        $response->assertHasErrors(['transaction_id is required']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\DeleteTransactionTool
     */
    public function testGivenMissingTransactionWhenDeletingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, DeleteTransactionTool::class, ['transaction_id' => 999_999]);
        $response->assertHasErrors(['not found']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
