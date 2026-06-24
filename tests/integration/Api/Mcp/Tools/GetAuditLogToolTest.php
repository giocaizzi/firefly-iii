<?php

/*
 * GetAuditLogToolTest.php
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
use FireflyIII\Mcp\Tools\GetAuditLogTool;
use FireflyIII\Repositories\AuditLogEntry\ALERepositoryInterface;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers GetAuditLogTool — returns group + journal audit entries, and the error paths.
 *
 * @internal
 *
 * @coversNothing
 */
final class GetAuditLogToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\GetAuditLogTool
     */
    public function testGivenTransactionWithAuditEntriesWhenReadingThenReturnsThem(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $expense     = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Groceries');
        $group       = $this->createWithdrawal($user, $source, $expense, '10.00', 'Lunch');
        $journal     = $group->transactionJournals()->first();

        $repository  = app(ALERepositoryInterface::class);
        $repository->store([
            'auditable' => $group,
            'changer'   => $user,
            'action'    => 'update_description',
            'before'    => 'Lunch',
            'after'     => 'Dinner'
        ]);
        $repository->store([
            'auditable' => $journal,
            'changer'   => $user,
            'action'    => 'update_transaction_type',
            'before'    => 'Withdrawal',
            'after'     => 'Transfer'
        ]);

        $response = $this->invokeTool($user, GetAuditLogTool::class, ['transaction_id' => $group->id]);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertSame(2, $payload['count']);
        $actions = array_column($payload['entries'], 'action');
        self::assertContains('update_description', $actions);
        self::assertContains('update_transaction_type', $actions);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\GetAuditLogTool
     */
    public function testGivenTransactionWithoutEntriesWhenReadingThenReturnsEmpty(): void
    {
        $user     = $this->createAuthenticatedUser();
        $source   = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $expense  = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Groceries');
        $group    = $this->createWithdrawal($user, $source, $expense, '10.00', 'Lunch');

        $response = $this->invokeTool($user, GetAuditLogTool::class, ['transaction_id' => $group->id]);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertSame(0, $payload['count']);
        self::assertSame([], $payload['entries']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\GetAuditLogTool
     */
    public function testGivenMissingTransactionWhenReadingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, GetAuditLogTool::class, ['transaction_id' => 999_999]);
        $response->assertHasErrors(['not found']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
