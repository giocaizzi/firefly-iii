<?php

/*
 * OptionalTransactionFieldsTest.php
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
use FireflyIII\Mcp\Tools\CreateWithdrawalTool;
use FireflyIII\Mcp\Tools\UpdateTransactionTool;
use FireflyIII\Models\TransactionJournal;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers the optional Firefly passthrough fields (meta dates, references, foreign amount / currency)
 * on the create, bulk-create and update transaction tools.
 *
 * @internal
 *
 * @coversNothing
 */
final class OptionalTransactionFieldsTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\BulkCreateTransactionsTool
     */
    public function testGivenExternalIdWhenBulkCreatingThenStoredAndEchoed(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $destination = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Groceries');

        $payload = $this->decodeJson($this->invokeTool($user, BulkCreateTransactionsTool::class, [
            'transactions' => [[
                'type'               => 'withdrawal',
                'source_id'          => $source->id,
                'destination_id'     => $destination->id,
                'amount'             => '9.99',
                'date'               => '2026-05-17',
                'description'        => 'Statement row',
                'external_id'        => 'stmt-2026-05-0001',
                'internal_reference' => 'ref-1',
                'process_date'       => '2026-05-18T10:00:00+02:00'
            ]],
            'verbose'      => true
        ])->assertOk());

        $split = $payload['data']['transactions'][0]['document']['data']['attributes']['transactions'][0];
        self::assertSame('stmt-2026-05-0001', $split['external_id']);
        self::assertSame('ref-1', $split['internal_reference']);
        self::assertStringStartsWith('2026-05-18', (string) $split['process_date']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\CreateWithdrawalTool
     */
    public function testGivenBookDateAndForeignAmountWhenCreatingWithdrawalThenStoredAndEchoed(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'Credit card');
        $destination = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop');

        $payload = $this->decodeJson($this->invokeTool($user, CreateWithdrawalTool::class, [
            'source_id'             => $source->id,
            'destination_id'        => $destination->id,
            'amount'                => '23.10',
            'date'                  => '2026-05-17',
            'book_date'             => '2026-05-20',
            'description'           => 'US purchase',
            'foreign_amount'        => '25.00',
            'foreign_currency_code' => 'USD',
            'verbose'               => true
        ])->assertOk());

        $split = $payload['data']['attributes']['transactions'][0];
        self::assertStringStartsWith('2026-05-20', (string) $split['book_date']);
        self::assertSame('USD', $split['foreign_currency_code']);
        self::assertSame(0, bccomp('25.00', (string) $split['foreign_amount'], 2));
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\CreateWithdrawalTool
     */
    public function testGivenForeignAmountWithoutCurrencyWhenCreatingThenInvalidArgumentAndNoWrite(): void
    {
        $user   = $this->createAuthenticatedUser();
        $source = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $before = TransactionJournal::count();

        $this->invokeTool($user, CreateWithdrawalTool::class, [
            'source_id'        => $source->id,
            'destination_name' => 'Shop',
            'amount'           => '1.00',
            'date'             => '2026-05-17',
            'description'      => 'No currency',
            'foreign_amount'   => '2.00'
        ])->assertHasErrors(['invalid_argument', '"field":"foreign_currency_code"']);
        self::assertSame($before, TransactionJournal::count());
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\BulkCreateTransactionsTool
     * @covers \FireflyIII\Mcp\Tools\CreateWithdrawalTool
     */
    public function testGivenUnparseableBookDateWhenCreatingThenInvalidArgumentAndNoWrite(): void
    {
        $user   = $this->createAuthenticatedUser();
        $source = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $before = TransactionJournal::count();
        $line   = [
            'source_id'        => $source->id,
            'destination_name' => 'Shop',
            'amount'           => '1.00',
            'date'             => '2026-05-17',
            'description'      => 'Bad date',
            'book_date'        => 'not-a-date'
        ];

        $this->invokeTool($user, CreateWithdrawalTool::class, $line)->assertHasErrors(['invalid_argument', '"field":"book_date"']);
        $this->invokeTool($user, BulkCreateTransactionsTool::class, ['transactions' => [['type' => 'withdrawal', ...$line]]])
            ->assertHasErrors(['invalid_argument', '"field":"transactions.0.book_date"']);
        self::assertSame($before, TransactionJournal::count());
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\UpdateTransactionTool
     */
    public function testGivenBookDateWhenUpdatingThenStoredAndEchoed(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $destination = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Groceries');
        $group       = $this->createWithdrawal($user, $source, $destination, '10.00', 'Original');

        $payload = $this->decodeJson($this->invokeTool($user, UpdateTransactionTool::class, [
            'transaction_id' => $group->id,
            'book_date'      => '2026-06-02',
            'external_id'    => 'stmt-42',
            'verbose'        => true
        ])->assertOk());

        $split = $payload['data']['attributes']['transactions'][0];
        self::assertStringStartsWith('2026-06-02', (string) $split['book_date']);
        self::assertSame('stmt-42', $split['external_id']);
        self::assertSame('Original', $split['description']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
