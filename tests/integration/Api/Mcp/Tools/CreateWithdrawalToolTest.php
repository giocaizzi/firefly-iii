<?php

/*
 * CreateWithdrawalToolTest.php
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
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers CreateWithdrawalTool — validation-failure short-circuit (must NOT
 * touch the DB) and idempotency-key format guard. The successful-create path is exercised
 * separately by the idempotency-replay scenario which seeds an existing journal via the
 * repository so the test does not depend on the date-string defect queued as observation A3.
 *
 * @internal
 *
 * @coversNothing
 */
final class CreateWithdrawalToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\CreateWithdrawalTool
     */
    public function testGivenInvalidIdempotencyKeyWhenCreatingWithdrawalThenRespondsWithError(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $destination = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Groceries');
        $before      = TransactionJournal::count();

        $response = $this->invokeTool($user, CreateWithdrawalTool::class, [
            'source_id'        => $source->id,
            'destination_id'   => $destination->id,
            'destination_name' => $destination->name,
            'amount'           => '12.50',
            'date'             => '2026-05-17',
            'description'      => 'Lunch',
            'idempotency_key'  => "non\x00ascii"
        ]);
        $response->assertHasErrors(['idempotency_key must contain only printable ASCII']);
        self::assertSame($before, TransactionJournal::count());
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\CreateWithdrawalTool
     */
    public function testGivenMissingAmountWhenCreatingWithdrawalThenRespondsWithErrorAndDoesNotWrite(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $destination = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Groceries');
        $before      = TransactionJournal::count();

        $response = $this->invokeTool($user, CreateWithdrawalTool::class, [
            'source_id'        => $source->id,
            'destination_id'   => $destination->id,
            'destination_name' => $destination->name,
            'date'             => '2026-05-17',
            'description'      => 'No amount'
        ]);
        $response->assertHasErrors();

        self::assertSame($before, TransactionJournal::count(), 'Validation failure must not persist a journal.');
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\CreateWithdrawalTool
     */
    public function testGivenValidPayloadWhenCreatingWithdrawalThenPersistsAndReturnsJournalId(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $destination = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Groceries');
        $before      = TransactionJournal::count();

        $response = $this->invokeTool($user, CreateWithdrawalTool::class, [
            'source_id'        => $source->id,
            'destination_id'   => $destination->id,
            'destination_name' => $destination->name,
            'amount'           => '12.50',
            'date'             => '2026-05-17',
            'description'      => 'Lunch'
        ]);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertArrayHasKey('id', $payload['data']);
        self::assertSame($before + 1, TransactionJournal::count());
        $journalId = (int) $payload['data']['transactions'][0]['transaction_journal_id'];
        self::assertNotNull(TransactionJournal::find($journalId));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
