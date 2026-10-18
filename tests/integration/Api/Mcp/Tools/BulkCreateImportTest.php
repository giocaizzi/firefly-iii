<?php

/*
 * BulkCreateImportTest.php
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

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Mcp\Tools\BulkCreateTransactionsTool;
use FireflyIII\Mcp\Tools\MatchTransactionsTool;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers the import options of BulkCreateTransactionsTool — `dry_run`, `skip_duplicates`,
 * `duplicate_check` / `date_window_days`, `external_id` — and that a call without them is unchanged.
 *
 * @internal
 *
 * @coversNothing
 */
final class BulkCreateImportTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    private Account $checking;

    private Account $shop;

    private User $user;

    /**
     * @covers \FireflyIII\Mcp\Tools\BulkCreateTransactionsTool
     */
    public function testGivenAmountDateAccountCheckWhenDryRunningThenSkipsOnlyInsideTheWindow(): void
    {
        $existing = $this->spend('20.00', '2026-03-01');
        $items    = [$this->item('20.00', '2026-03-02'), $this->item('21.00', '2026-03-02')];

        $sameDay = $this->bulk(['dry_run' => true, 'skip_duplicates' => true, 'duplicate_check' => 'amount_date_account', 'transactions' => $items]);
        self::assertSame(2, $sameDay['data']['would_create']);

        $windowed = $this->bulk([
            'dry_run'          => true,
            'skip_duplicates'  => true,
            'duplicate_check'  => 'amount_date_account',
            'date_window_days' => 1,
            'transactions'     => $items
        ]);
        self::assertSame(1, $windowed['data']['would_create']);
        self::assertSame(
            [['index' => 0, 'action' => 'skip', 'reason' => 'amount_date_account', 'transaction_ids' => [$existing->id]], ['index' => 1, 'action' => 'create']],
            $windowed['data']['items']
        );

        $externalOnly = $this->bulk(['dry_run' => true, 'skip_duplicates' => true, 'date_window_days' => 1, 'transactions' => $items]);
        self::assertSame(2, $externalOnly['data']['would_create'], 'The default duplicate_check only compares external_id.');
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\BulkCreateTransactionsTool
     */
    public function testGivenDryRunWhenBulkCreatingThenWritesNothingAndReturnsThePlan(): void
    {
        $before = TransactionJournal::count();

        $data = $this->bulk(['dry_run' => true, 'transactions' => [$this->item('10.00', '2026-03-01'), $this->item('20.00', '2026-03-02')]]);

        self::assertSame($before, TransactionJournal::count());
        self::assertTrue($data['data']['dry_run']);
        self::assertSame(2, $data['data']['would_create']);
        self::assertSame(0, $data['data']['would_skip']);
        self::assertSame([['index' => 0, 'action' => 'create'], ['index' => 1, 'action' => 'create']], $data['data']['items']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\BulkCreateTransactionsTool
     */
    public function testGivenDryRunWithStoredIdempotencyKeyWhenBulkCreatingThenPlansAReplay(): void
    {
        $first = $this->bulk(['transactions' => [[...$this->item('10.00', '2026-03-01'), 'idempotency_key' => 'k-1']]]);
        $id    = $first['data']['transactions'][0]['transaction']['id'];

        $data = $this->bulk(['dry_run' => true, 'transactions' => [[...$this->item('10.00', '2026-03-01'), 'idempotency_key' => 'k-1']]]);

        self::assertSame([['index' => 0, 'action' => 'replay', 'transaction_id' => $id]], $data['data']['items']);
        self::assertSame(1, $data['data']['would_replay']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\BulkCreateTransactionsTool
     */
    public function testGivenDuplicateWithoutSkipWhenDryRunningThenItIsReportedButStillCreated(): void
    {
        $existing = $this->spend('10.00', '2026-03-01', 'EXT-9');

        $data = $this->bulk(['dry_run' => true, 'transactions' => [[...$this->item('10.00', '2026-03-01'), 'external_id' => 'EXT-9']]]);

        self::assertSame(
            [['index' => 0, 'action' => 'create', 'duplicate' => ['reason' => 'external_id', 'transaction_ids' => [$existing->id]]]],
            $data['data']['items']
        );
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\BulkCreateTransactionsTool
     */
    public function testGivenExistingExternalIdWhenSkippingDuplicatesThenOnlyNewItemsAreCreated(): void
    {
        $existing = $this->spend('10.00', '2026-03-01', 'EXT-1');
        $before   = TransactionJournal::count();

        $data = $this->bulk([
            'skip_duplicates' => true,
            'transactions'    => [
                [...$this->item('10.00', '2026-03-01'), 'external_id' => 'EXT-1'],
                [...$this->item('15.00', '2026-03-01'), 'external_id' => 'EXT-2']
            ]
        ]);

        self::assertSame($before + 1, TransactionJournal::count());
        self::assertSame(1, $data['data']['created']);
        self::assertSame(1, $data['data']['skipped']);
        self::assertSame(2, $data['data']['total']);
        self::assertSame([['index' => 0, 'reason' => 'external_id', 'transaction_ids' => [$existing->id]]], $data['data']['skipped_items']);
        self::assertSame(1, $data['data']['transactions'][0]['index']);

        // The external_id is stored, so the new row is now found by reference.
        $match = $this->decodeJson($this->invokeTool($this->user, MatchTransactionsTool::class, [
            'rows' => [['date' => '2026-03-01', 'amount' => '0', 'external_id' => 'EXT-2']]
        ]));
        self::assertSame('matched', $match['data'][0]['status']);
        self::assertSame($data['data']['transactions'][0]['transaction']['id'], $match['data'][0]['transaction']['id']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\BulkCreateTransactionsTool
     */
    public function testGivenInvalidDuplicateCheckWhenBulkCreatingThenRejectsIt(): void
    {
        $this->invokeTool($this->user, BulkCreateTransactionsTool::class, [
            'duplicate_check' => 'fuzzy',
            'transactions'    => [$this->item('10.00', '2026-03-01')]
        ])->assertHasErrors(['"field":"duplicate_check"']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\BulkCreateTransactionsTool
     */
    public function testGivenNoImportOptionsWhenBulkCreatingThenResponseShapeIsUnchanged(): void
    {
        $data = $this->bulk(['transactions' => [$this->item('10.00', '2026-03-01')]]);

        self::assertSame(['created', 'replayed', 'total', 'transactions'], array_keys($data['data']));
        self::assertSame(1, $data['data']['created']);
        self::assertSame(1, $data['data']['total']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
        $this->user     = $this->createAuthenticatedUser();
        $this->checking = $this->createAccount($this->user, AccountTypeEnum::ASSET, 'Checking');
        $this->shop     = $this->createAccount($this->user, AccountTypeEnum::EXPENSE, 'Shop');
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function bulk(array $args): array
    {
        $response = $this->invokeTool($this->user, BulkCreateTransactionsTool::class, $args);
        $response->assertHasNoErrors();

        return $this->decodeJson($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $amount, string $date): array
    {
        return [
            'type'             => 'withdrawal',
            'source_id'        => $this->checking->id,
            'destination_id'   => $this->shop->id,
            'destination_name' => $this->shop->name,
            'amount'           => $amount,
            'date'             => $date,
            'description'      => 'Purchase'
        ];
    }

    private function spend(string $amount, string $date, null|string $externalId = null): TransactionGroup
    {
        return $this->storeTransaction($this->user, [
            'type'             => TransactionTypeEnum::WITHDRAWAL->value,
            'date'             => Carbon::parse($date),
            'amount'           => $amount,
            'description'      => 'Purchase',
            'source_id'        => $this->checking->id,
            'destination_id'   => $this->shop->id,
            'destination_name' => $this->shop->name,
            'reconciled'       => false,
            'external_id'      => $externalId
        ]);
    }
}
