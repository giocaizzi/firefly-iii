<?php

/*
 * SummaryBasicToolTest.php
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

use FireflyIII\Mcp\Tools\SummaryBasicTool;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\TestCase;

/**
 * Covers SummaryBasicTool — current-month default and explicit date range.
 *
 * @internal
 *
 * @coversNothing
 */
final class SummaryBasicToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;

    /**
     * @covers \FireflyIII\Mcp\Tools\SummaryBasicTool
     */
    public function testGivenAuthenticatedUserWhenSummarisingThenReturnsDataAndDateMeta(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, SummaryBasicTool::class, []);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertArrayHasKey('data', $payload);
        self::assertArrayHasKey('start', $payload['meta']);
        self::assertArrayHasKey('end', $payload['meta']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\SummaryBasicTool
     */
    public function testGivenDateRangeFilterWhenSummarisingThenReflectsArgs(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, SummaryBasicTool::class, ['start' => '2026-01-01', 'end' => '2026-01-31']);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertSame('2026-01-01', $payload['meta']['start']);
        self::assertSame('2026-01-31', $payload['meta']['end']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
