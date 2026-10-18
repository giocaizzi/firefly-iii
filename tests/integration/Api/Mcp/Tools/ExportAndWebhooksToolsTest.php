<?php

/*
 * ExportAndWebhooksToolsTest.php
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
use FireflyIII\Mcp\Tools\ExportTransactionsTool;
use FireflyIII\Mcp\Tools\WebhooksWriteTool;
use FireflyIII\Models\Webhook;
use FireflyIII\Support\Facades\AppConfiguration;
use Tests\integration\Api\Mcp\Concerns\CallsApiTools;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ExportAndWebhooksToolsTest extends TestCase
{
    use CallsApiTools;
    use EnsuresPassportKeys;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\ExportTransactionsTool
     */
    public function testExportReturnsCsvWithColumnsAndCap(): void
    {
        $user    = $this->createAuthenticatedUser();
        $asset   = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $expense = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Bakery');
        $this->createWithdrawal($user, $asset, $expense, '3.20', 'Bread');
        $this->createWithdrawal($user, $asset, $expense, '4.10', 'Cake, "large"');

        $full  = $this->responseText($this->invokeTool($user, ExportTransactionsTool::class, ['accounts' => [$asset->id]]));
        $lines = array_values(array_filter(explode("\n", $full)));
        self::assertCount(3, $lines);
        self::assertStringContainsString('description', $lines[0]);

        $narrow = $this->responseText($this->invokeTool($user, ExportTransactionsTool::class, ['columns' => ['description', 'amount']]));
        self::assertSame("description,amount\n", strtok($narrow, "\n") . "\n");
        self::assertStringContainsString('"Cake, ""large"""', $narrow);

        [$head, $row] = explode("\n", $narrow);
        $cap    = strlen($head) + strlen($row) + 2;
        $capped = $this->responseText($this->invokeTool($user, ExportTransactionsTool::class, ['columns' => ['description', 'amount'], 'max_bytes' => $cap]));
        self::assertStringContainsString('# truncated: 1 of 2 rows shown', $capped);

        $this->invalidArgument($user, ExportTransactionsTool::class, ['columns' => ['nope']], 'columns');
        $this->invalidArgument($user, ExportTransactionsTool::class, ['max_bytes' => ExportTransactionsTool::MAX_BYTES + 1], 'max_bytes');
        $this->invalidArgument($user, ExportTransactionsTool::class, ['start' => '2026-02-01', 'end' => '2026-01-01'], 'start');
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\WebhooksWriteTool
     */
    public function testWebhookRoundTripOnlyWhenEnabled(): void
    {
        $user = $this->createAuthenticatedUser();
        $data = [
            'title'      => 'Notify',
            'url'        => 'https://1.1.1.1/hook',
            'triggers'   => ['STORE_TRANSACTION'],
            'responses'  => ['TRANSACTIONS'],
            'deliveries' => ['JSON']
        ];

        AppConfiguration::set('allow_webhooks', false);
        $this->invokeTool($user, WebhooksWriteTool::class, ['action' => 'create', 'data' => $data])->assertHasErrors(['Webhooks are not enabled']);
        self::assertSame(0, Webhook::count());

        AppConfiguration::set('allow_webhooks', true);
        $created = $this->okJson($user, WebhooksWriteTool::class, ['action' => 'create', 'data' => $data]);
        $id      = $created['data']['id'];
        self::assertSame(['STORE_TRANSACTION'], $created['data']['triggers']);
        self::assertArrayNotHasKey('secret', $created['data']);

        $updated = $this->okJson($user, WebhooksWriteTool::class, [
            'action' => 'update',
            'id'     => $id,
            'data'   => [...$data, 'title' => 'Notify me', 'active' => false]
        ]);
        self::assertSame('Notify me', $updated['data']['title']);
        $this->invalidArgument(
            $user,
            WebhooksWriteTool::class,
            ['action' => 'create', 'data' => [...$data, 'title' => 'Other', 'url' => 'ftp://example.test']],
            'data.url'
        );

        $this->invokeTool($user, WebhooksWriteTool::class, ['action' => 'delete', 'id' => $id])->assertHasNoErrors();
        self::assertFalse(Webhook::whereKey($id)->exists());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
