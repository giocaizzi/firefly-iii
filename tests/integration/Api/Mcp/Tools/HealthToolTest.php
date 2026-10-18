<?php

/*
 * HealthToolTest.php
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

use FireflyIII\Mcp\Tools\HealthTool;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\TestCase;

/**
 * Covers HealthTool — versions, user, features and write access.
 *
 * @internal
 *
 * @coversNothing
 */
final class HealthToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;

    /**
     * @covers \FireflyIII\Mcp\Tools\HealthTool
     */
    public function testGivenOwnerWhenCheckingHealthThenReportsVersionUserFeaturesAndWriteAccess(): void
    {
        $user = $this->createAuthenticatedUser();
        config(['mcp.build' => 'abc1234']);

        $response = $this->invokeTool($user, HealthTool::class);
        $response->assertOk();

        $data = $this->decodeJson($response)['data'];
        self::assertSame(config('firefly.version'), $data['firefly_version']);
        self::assertSame('abc1234', $data['mcp']['build']);
        self::assertSame($user->id, $data['user']['id']);
        self::assertSame('test@email.com', $data['user']['email']);
        self::assertTrue($data['can_write']);
        foreach (['webhooks', 'exchange_rates', 'handle_debts', 'expression_engine', 'export'] as $feature) {
            self::assertIsBool($data['features'][$feature]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
