<?php

/*
 * UserAccountsResourceTest.php
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

namespace Tests\integration\Api\Mcp\Resources;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Mcp\Resources\UserAccountsResource;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers UserAccountsResource. The resource invokes AccountEnrichment so the
 * transformer hydrates properly; the seeded asset account is asserted in the flat shape.
 *
 * @internal
 *
 * @coversNothing
 */
final class UserAccountsResourceTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Resources\UserAccountsResource
     */
    public function testGivenSeededAssetAccountWhenReadingUserAccountsThenIncludesFlatAccount(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');

        $response = $this->invokeResource($user, UserAccountsResource::class);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertNotEmpty($payload['data']);
        $row = $payload['data'][0];
        self::assertArrayNotHasKey('attributes', $row);
        self::assertArrayHasKey('id', $row);
        self::assertArrayHasKey('name', $row);
        self::assertArrayHasKey('type', $row);
        self::assertArrayHasKey('currency_code', $row);
        self::assertArrayHasKey('active', $row);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
