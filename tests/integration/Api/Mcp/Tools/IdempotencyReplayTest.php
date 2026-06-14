<?php

/*
 * IdempotencyReplayTest.php
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
use FireflyIII\Mcp\Tools\CreateWithdrawalTool;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalMeta;
use Tests\integration\Api\Mcp\Concerns\AssertsAuditLog;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers idempotency replay (CreateWithdrawalTool branch).
 *
 * Seeds a withdrawal directly via the repository and tags its first journal with the
 * `mcp_idempotency_key` meta. A second create call carrying the same key must NOT
 * create a new journal — it returns the pre-existing journal and emits an audit-log
 * line with `idempotent_replay = true`.
 *
 * @internal
 *
 * @coversNothing
 */
final class IdempotencyReplayTest extends TestCase
{
    use AssertsAuditLog;
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\CreateWithdrawalTool
     */
    public function testGivenExistingIdempotencyKeyWhenCreatingWithdrawalAgainThenReplaysOriginalJournal(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $destination = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Groceries');
        $group       = $this->createWithdrawal($user, $source, $destination, '17.00', 'Original');
        $originalId  = (int) $group->transactionJournals()->first()->id;

        // Tag the journal with the idempotency key the second tool call will present.
        // Mirror WritesTransactions::IDEMPOTENCY_META_NAME (constant cannot be addressed via the trait FQN).
        TransactionJournalMeta::create([
            'transaction_journal_id' => $originalId,
            'name'                   => 'mcp_idempotency_key',
            'data'                   => 'abc'
        ]);

        $this->captureAuditChannel();
        $before = TransactionJournal::count();

        $response = $this->invokeTool($user, CreateWithdrawalTool::class, [
            'source_id'        => $source->id,
            'destination_id'   => $destination->id,
            'destination_name' => $destination->name,
            'amount'           => '99.99',
            'date'             => '2026-05-17',
            'description'      => 'Should not persist',
            'idempotency_key'  => 'abc'
        ]);
        $response->assertOk();

        self::assertSame($before, TransactionJournal::count(), 'Replay must not create a new journal.');
        $this->assertAuditInfo(
            static fn(string $message, array $ctx): bool => (
                'MCP write' === $message
                && true === ($ctx['idempotent_replay'] ?? null)
                && ($ctx['transaction_id'] ?? null) === $originalId
            )
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
