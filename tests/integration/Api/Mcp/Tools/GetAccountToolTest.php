<?php

/*
 * GetAccountToolTest.php
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
use FireflyIII\Mcp\Tools\GetAccountTool;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers WIP_MCP §4.1 GetAccountTool. Asserts the flat reduced envelope returned for an
 * existing account plus the not-found error path.
 *
 * @internal
 *
 * @coversNothing
 */
final class GetAccountToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\GetAccountTool
     */
    public function testGivenExistingAccountWhenGettingByIdThenReturnsFlatEnvelope(): void
    {
        $user    = $this->createAuthenticatedUser();
        $account = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');

        $response = $this->invokeTool($user, GetAccountTool::class, ['id' => $account->id]);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertSame((int) $account->id, $payload['data']['id']);
        self::assertSame('Checking', $payload['data']['name']);
        self::assertSame('asset', $payload['data']['type']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\GetAccountTool
     */
    public function testGivenMissingAccountWhenGettingByIdThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, GetAccountTool::class, ['id' => 999_999]);
        $response->assertHasErrors(['Account not found']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\GetAccountTool
     */
    public function testGivenMissingAccountWhenGettingByNameThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, GetAccountTool::class, ['name' => 'No-such-account']);
        $response->assertHasErrors(['Account not found']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
