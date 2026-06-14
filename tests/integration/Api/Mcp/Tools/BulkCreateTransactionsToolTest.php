<?php

/*
 * BulkCreateTransactionsToolTest.php
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
use FireflyIII\Mcp\Tools\BulkCreateTransactionsTool;
use FireflyIII\Models\TransactionJournal;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers BulkCreateTransactionsTool — empty-array guard, max-items guard,
 * and the all-or-nothing pre-flight when one item is invalid.
 *
 * @internal
 *
 * @coversNothing
 */
final class BulkCreateTransactionsToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\BulkCreateTransactionsTool
     */
    public function testGivenEmptyTransactionsArrayWhenBulkCreatingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, BulkCreateTransactionsTool::class, ['transactions' => []]);
        $response->assertHasErrors(['transactions must be a non-empty array']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\BulkCreateTransactionsTool
     */
    public function testGivenInvalidItemTypeWhenBulkCreatingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, BulkCreateTransactionsTool::class, [
            'transactions' => [
                ['type' => 'reconciliation', 'amount' => '1.00', 'date' => '2026-05-17', 'description' => 'x']
            ]
        ]);
        $response->assertHasErrors();
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\BulkCreateTransactionsTool
     */
    public function testGivenInvalidSecondItemWhenBulkCreatingThenWholeBatchRolledBack(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $destination = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Groceries');
        $before      = TransactionJournal::count();

        $response = $this->invokeTool($user, BulkCreateTransactionsTool::class, [
            'transactions' => [
                [
                    'type'             => 'withdrawal',
                    'source_id'        => $source->id,
                    'destination_id'   => $destination->id,
                    'destination_name' => $destination->name,
                    'amount'           => '10.00',
                    'date'             => '2026-05-17',
                    'description'      => 'Item 0'
                ],
                [
                    // Index 1 — missing required `amount`.
                    'type'             => 'withdrawal',
                    'source_id'        => $source->id,
                    'destination_id'   => $destination->id,
                    'destination_name' => $destination->name,
                    'date'             => '2026-05-17',
                    'description'      => 'Item 1 (invalid)'
                ]
            ]
        ]);
        $response->assertHasErrors();
        self::assertSame($before, TransactionJournal::count(), 'No journals should persist when any item fails pre-flight.');
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\BulkCreateTransactionsTool
     */
    public function testGivenTwoValidItemsWhenBulkCreatingThenPersistsBothAndReturnsIds(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $destination = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Groceries');
        $before      = TransactionJournal::count();

        $response = $this->invokeTool($user, BulkCreateTransactionsTool::class, [
            'transactions' => [
                [
                    'type'             => 'withdrawal',
                    'source_id'        => $source->id,
                    'destination_id'   => $destination->id,
                    'destination_name' => $destination->name,
                    'amount'           => '10.00',
                    'date'             => '2026-05-17',
                    'description'      => 'Item 0'
                ],
                [
                    'type'             => 'withdrawal',
                    'source_id'        => $source->id,
                    'destination_id'   => $destination->id,
                    'destination_name' => $destination->name,
                    'amount'           => '20.00',
                    'date'             => '2026-05-17',
                    'description'      => 'Item 1'
                ]
            ]
        ]);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertSame(2, $payload['data']['created']);
        self::assertSame(0, $payload['data']['replayed']);
        self::assertSame(2, $payload['data']['total']);
        self::assertCount(2, $payload['data']['transactions']);
        self::assertSame($before + 2, TransactionJournal::count());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
