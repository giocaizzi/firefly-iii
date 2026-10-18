<?php

/*
 * UserAccountsQueryResourceTest.php
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
use Tests\integration\Api\Mcp\Concerns\CallsMcpEndpoint;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers UserAccountsQueryResource (firefly-iii://user/accounts?{params}) over the HTTP
 * transport: paging, type and name filters, and rejection of invalid parameters. The plain
 * firefly-iii://user/accounts URI keeps resolving to the unpaged resource.
 *
 * @internal
 *
 * @coversNothing
 */
final class UserAccountsQueryResourceTest extends TestCase
{
    use CallsMcpEndpoint;
    use EnsuresPassportKeys;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Resources\UserAccountsQueryResource
     */
    public function testGivenInvalidParamsWhenReadingAccountsThenReturnsError(): void
    {
        $this->actingAs($this->createAuthenticatedUser(), 'api');

        foreach (['limit=500', 'page=0', 'type=zzz'] as $params) {
            $body = $this
                ->mcpCall('resources/read', ['uri' => 'firefly-iii://user/accounts?' . $params])
                ->assertOk()
                ->json();
            $text = (string) ($body['result']['contents'][0]['text'] ?? $body['error']['message'] ?? '');
            self::assertStringContainsString('invalid_argument', $text, $params);
        }
    }

    /**
     * @covers \FireflyIII\Mcp\Resources\UserAccountsQueryResource
     */
    public function testGivenQueryStringWhenReadingAccountsThenPagesAndFilters(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user, 'api');
        $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $this->createAccount($user, AccountTypeEnum::ASSET, 'Savings');
        $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Supermarket');

        $paged = $this->read('firefly-iii://user/accounts?type=asset&limit=1');
        self::assertCount(1, $paged['data']);
        self::assertSame(2, $paged['meta']['pagination']['total']);
        self::assertTrue($paged['meta']['has_more']);
        self::assertSame(2, $paged['meta']['next_page']);
        self::assertArrayHasKey('currency_code', $paged['data'][0]);

        $byName = $this->read('firefly-iii://user/accounts?query=super');
        self::assertSame(['Supermarket'], array_column($byName['data'], 'name'));

        $plain = $this->read('firefly-iii://user/accounts');
        self::assertCount(3, $plain['data']);
        self::assertArrayNotHasKey('meta', $plain);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
        $this->enableMcp();
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $uri): array
    {
        $body = $this->mcpCall('resources/read', ['uri' => $uri])->assertOk()->json();
        $text = $body['result']['contents'][0]['text'] ?? null;
        self::assertIsString($text, (string) json_encode($body));

        /** @var array<string, mixed> */
        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }
}
