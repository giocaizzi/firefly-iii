<?php

/*
 * ListWebhooksToolTest.php
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

use FireflyIII\Mcp\Tools\ListWebhooksTool;
use FireflyIII\Support\Facades\FireflyConfig;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers ListWebhooksTool. Disabled flag returns an MCP error; enabled
 * returns the seeded webhook inside the reduced envelope.
 *
 * @internal
 *
 * @coversNothing
 */
final class ListWebhooksToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\ListWebhooksTool
     */
    public function testGivenSeededWebhookWhenListingThenReturnsWebhookInEnvelope(): void
    {
        FireflyConfig::set('allow_webhooks', true);

        $user    = $this->createAuthenticatedUser();
        $webhook = $this->createWebhook($user, 'Test webhook');

        $response = $this->invokeTool($user, ListWebhooksTool::class, []);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertCount(1, $payload['data']);
        self::assertSame((int) $webhook->id, $payload['data'][0]['id']);
        self::assertSame('Test webhook', $payload['data'][0]['title']);
        self::assertArrayHasKey('pagination', $payload['meta']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ListWebhooksTool
     */
    public function testGivenWebhooksDisabledWhenListingThenRespondsWithError(): void
    {
        FireflyConfig::set('allow_webhooks', false);

        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, ListWebhooksTool::class, []);
        $response->assertHasErrors(['Webhooks are not enabled']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
