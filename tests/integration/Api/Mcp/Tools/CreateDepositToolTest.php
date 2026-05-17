<?php

/*
 * CreateDepositToolTest.php
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
use FireflyIII\Mcp\Tools\CreateDepositTool;
use FireflyIII\Models\TransactionJournal;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers WIP_MCP §4.5 CreateDepositTool — validation-failure short-circuit.
 *
 * @internal
 *
 * @coversNothing
 */
final class CreateDepositToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\CreateDepositTool
     */
    public function testGivenMissingAmountWhenCreatingDepositThenRespondsWithErrorAndDoesNotWrite(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::REVENUE, 'Salary');
        $destination = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $before      = TransactionJournal::count();

        $response = $this->invokeTool($user, CreateDepositTool::class, [
            'source_id'      => $source->id,
            'destination_id' => $destination->id,
            'date'           => '2026-05-17',
            'description'    => 'Salary payment'
        ]);
        $response->assertHasErrors();

        self::assertSame($before, TransactionJournal::count(), 'Validation failure must not persist a journal.');
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\CreateDepositTool
     */
    public function testGivenValidPayloadWhenCreatingDepositThenPersistsAndReturnsJournalId(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::REVENUE, 'Salary');
        $destination = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $before      = TransactionJournal::count();

        $response = $this->invokeTool($user, CreateDepositTool::class, [
            'source_id'      => $source->id,
            'destination_id' => $destination->id,
            'amount'         => '500.00',
            'date'           => '2026-05-17',
            'description'    => 'Monthly salary'
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
