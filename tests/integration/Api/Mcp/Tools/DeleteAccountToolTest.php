<?php

/*
 * DeleteAccountToolTest.php
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
use FireflyIII\Mcp\Tools\DeleteAccountTool;
use FireflyIII\Models\Account;
use Tests\integration\Api\Mcp\Concerns\AssertsAuditLog;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers DeleteAccountTool — plain delete, merge-into-other-account, and error paths.
 *
 * @internal
 *
 * @coversNothing
 */
final class DeleteAccountToolTest extends TestCase
{
    use AssertsAuditLog;
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\DeleteAccountTool
     */
    public function testGivenExistingAccountWhenDeletingThenRemovedAndAuditLogged(): void
    {
        $user    = $this->createAuthenticatedUser();
        $account = $this->createAccount($user, AccountTypeEnum::ASSET, 'Old account');

        $this->captureAuditChannel();

        $response = $this->invokeTool($user, DeleteAccountTool::class, ['account_id' => $account->id]);
        $response->assertOk();

        self::assertNull(Account::find($account->id), 'Account should be deleted.');
        $this->assertAuditInfo(
            static fn(string $message, array $ctx): bool => (
                'MCP write' === $message
                && ($ctx['account_id'] ?? null) === $account->id
                && array_key_exists('move_to_account_id', $ctx)
                && null === $ctx['move_to_account_id']
            )
        );
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\DeleteAccountTool
     */
    public function testGivenMoveToWhenDeletingThenTransactionsMovedAndAccountRemoved(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'Phantom');
        $keep        = $this->createAccount($user, AccountTypeEnum::ASSET, 'Real');
        $destination = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop');
        $group       = $this->createWithdrawal($user, $source, $destination, '15.00', 'Spend');

        $response = $this->invokeTool($user, DeleteAccountTool::class, [
            'account_id'         => $source->id,
            'move_to_account_id' => $keep->id
        ]);
        $response->assertOk();

        self::assertNull(Account::find($source->id), 'Source account should be deleted.');
        $movedAccountId = (int) $group->transactionJournals()->first()
            ->transactions()->where('amount', '<', 0)->first()->account_id;
        self::assertSame($keep->id, $movedAccountId, 'Withdrawal source should now be the kept account.');
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\DeleteAccountTool
     */
    public function testGivenMoveToEqualToAccountWhenDeletingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $account  = $this->createAccount($user, AccountTypeEnum::ASSET, 'Same');
        $response = $this->invokeTool($user, DeleteAccountTool::class, [
            'account_id'         => $account->id,
            'move_to_account_id' => $account->id
        ]);
        $response->assertHasErrors(['must differ']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\DeleteAccountTool
     */
    public function testGivenMissingAccountWhenDeletingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, DeleteAccountTool::class, ['account_id' => 999_999]);
        $response->assertHasErrors(['not found']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
