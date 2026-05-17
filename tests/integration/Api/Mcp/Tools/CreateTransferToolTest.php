<?php

/*
 * CreateTransferToolTest.php
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
use FireflyIII\Mcp\Tools\CreateTransferTool;
use FireflyIII\Models\AccountMeta;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionJournal;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers WIP_MCP §4.5 CreateTransferTool — cross-currency guard (D-029) and the
 * validation-failure short-circuit.
 *
 * @internal
 *
 * @coversNothing
 */
final class CreateTransferToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\CreateTransferTool
     */
    public function testGivenCrossCurrencyAccountsWhenCreatingTransferThenRespondsWithErrorAndDoesNotWrite(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'EUR account');
        $destination = $this->createAccount($user, AccountTypeEnum::ASSET, 'USD account');

        // Re-stamp the destination's account currency to USD so the cross-currency guard fires.
        $usd = TransactionCurrency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'enabled' => true]);
        AccountMeta::where('account_id', $destination->id)->where('name', 'currency_id')->update(['data' => $usd->id]);

        $before   = TransactionJournal::count();
        $response = $this->invokeTool($user, CreateTransferTool::class, [
            'source_id'      => $source->id,
            'destination_id' => $destination->id,
            'amount'         => '50.00',
            'date'           => '2026-05-17',
            'description'    => 'cross-currency move'
        ]);
        $response->assertHasErrors(['Cross-currency transfers are not supported']);
        self::assertSame($before, TransactionJournal::count());
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\CreateTransferTool
     */
    public function testGivenMissingAmountWhenCreatingTransferThenRespondsWithErrorAndDoesNotWrite(): void
    {
        $user        = $this->createAuthenticatedUser();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'A');
        $destination = $this->createAccount($user, AccountTypeEnum::ASSET, 'B');
        $before      = TransactionJournal::count();

        $response = $this->invokeTool($user, CreateTransferTool::class, [
            'source_id'      => $source->id,
            'destination_id' => $destination->id,
            'date'           => '2026-05-17',
            'description'    => 'no amount'
        ]);
        $response->assertHasErrors();
        self::assertSame($before, TransactionJournal::count());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
