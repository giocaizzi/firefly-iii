<?php

/*
 * CurrenciesToolsTest.php
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

use FireflyIII\Mcp\Tools\CurrenciesReadTool;
use FireflyIII\Mcp\Tools\CurrenciesWriteTool;
use FireflyIII\Models\CurrencyExchangeRate;
use FireflyIII\Repositories\User\UserRepositoryInterface;
use Tests\integration\Api\Mcp\Concerns\CallsApiTools;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class CurrenciesToolsTest extends TestCase
{
    use CallsApiTools;
    use EnsuresPassportKeys;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\CurrenciesReadTool
     * @covers \FireflyIII\Mcp\Tools\CurrenciesWriteTool
     */
    public function testCurrencyEnableDisablePrimaryAndAdminOnlyCreate(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->primaryCurrency();

        $enabled = $this->okJson($user, CurrenciesWriteTool::class, ['action' => 'enable', 'id' => 'GBP']);
        self::assertTrue($enabled['data']['enabled']);
        $get = $this->okJson($user, CurrenciesReadTool::class, ['action' => 'get', 'id' => 'GBP']);
        self::assertSame(['code' => 'GBP', 'enabled' => true], array_intersect_key($get['data'], ['code' => 0, 'enabled' => 0]));

        $primary = $this->okJson($user, CurrenciesWriteTool::class, ['action' => 'primary', 'id' => 'GBP']);
        self::assertTrue($primary['data']['primary']);
        self::assertSame('GBP', $this->okJson($user, CurrenciesReadTool::class, ['action' => 'get', 'id' => 'primary'])['data']['code']);
        $this->okJson($user, CurrenciesWriteTool::class, ['action' => 'primary', 'id' => 'EUR']);

        $disabled = $this->okJson($user, CurrenciesWriteTool::class, ['action' => 'disable', 'id' => 'GBP']);
        self::assertFalse($disabled['data']['enabled'] ?? false);

        $list = $this->okJson($user, CurrenciesReadTool::class, ['action' => 'list', 'limit' => 200, 'fields' => ['code']]);
        self::assertContains(['code' => 'EUR'], $list['data']);

        // creating a currency is an instance-admin action in the API
        $data = ['code' => 'XTS', 'name' => 'Test currency', 'symbol' => 'T', 'decimal_places' => 2];
        $this->invokeTool($user, CurrenciesWriteTool::class, ['action' => 'create', 'data' => $data])->assertHasErrors();
        app(UserRepositoryInterface::class)->attachRole($user, 'owner');
        $user->load('roles');
        $created = $this->okJson($user, CurrenciesWriteTool::class, ['action' => 'create', 'data' => $data]);
        self::assertSame('XTS', $created['data']['code']);
        $updated = $this->okJson($user, CurrenciesWriteTool::class, ['action' => 'update', 'id' => 'XTS', 'data' => ['name' => 'Testing currency']]);
        self::assertSame('Testing currency', $updated['data']['name']);
        $this->invokeTool($user, CurrenciesWriteTool::class, ['action' => 'delete', 'id' => 'XTS'])->assertHasNoErrors();
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\CurrenciesReadTool
     * @covers \FireflyIII\Mcp\Tools\CurrenciesWriteTool
     */
    public function testExchangeRateRoundTrip(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->primaryCurrency();

        $created = $this->okJson($user, CurrenciesWriteTool::class, [
            'action' => 'create',
            'kind'   => 'rate',
            'data'   => ['from' => 'EUR', 'to' => 'GBP', 'rate' => '0.85', 'date' => '2026-03-01']
        ]);
        $id      = $created['data']['id'];
        self::assertSame(
            ['from_currency_code' => 'EUR', 'to_currency_code' => 'GBP', 'rate' => '0.85', 'date' => '2026-03-01'],
            array_diff_key($created['data'], ['id' => 0])
        );

        $pair = $this->okJson($user, CurrenciesReadTool::class, ['action' => 'list', 'kind' => 'rate', 'from' => 'EUR', 'to' => 'GBP']);
        self::assertSame([$id], array_column($pair['data'], 'id'));
        $get = $this->okJson($user, CurrenciesReadTool::class, ['action' => 'get', 'kind' => 'rate', 'id' => $id]);
        self::assertSame('0.85', $get['data']['rate']);

        $updated = $this->okJson($user, CurrenciesWriteTool::class, ['action' => 'update', 'kind' => 'rate', 'id' => $id, 'data' => ['rate' => '0.86']]);
        self::assertSame('0.86', $updated['data']['rate']);

        $this->invalidArgument(
            $user,
            CurrenciesWriteTool::class,
            ['action' => 'create', 'kind' => 'rate', 'data' => ['from' => 'EUR', 'to' => 'GBP', 'rate' => '-1', 'date' => '2026-03-01']],
            'data.rate'
        );
        $this->invalidArgument($user, CurrenciesWriteTool::class, ['action' => 'enable', 'kind' => 'rate', 'id' => $id], 'action');

        $this->invokeTool($user, CurrenciesWriteTool::class, ['action' => 'delete', 'kind' => 'rate', 'id' => $id])->assertHasNoErrors();
        self::assertFalse(CurrencyExchangeRate::whereKey($id)->exists());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
