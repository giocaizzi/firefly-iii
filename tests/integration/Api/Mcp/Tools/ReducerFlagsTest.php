<?php

/*
 * ReducerFlagsTest.php
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

use FireflyIII\Mcp\Tools\ListAccountsTool;
use FireflyIII\Mcp\Tools\ListTagsTool;
use FireflyIII\Models\Tag;
use FireflyIII\User;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\TestCase;

/**
 * Covers the reducer-flag surface that AbstractMcpTool::respondJsonApi() exposes on every
 * tool that returns JSON:API data: `verbose: true` returns the raw JSON:API envelope, and
 * `include_nulls: true` preserves null-valued attributes through the reducer.
 *
 * The semantic assertions run against ListTagsTool because TagTransformer needs no
 * enrichment so the JSON:API document has real items to verify against. ListAccountsTool
 * is also exercised with `verbose: true` to confirm the flag is plumbed through
 * AbstractMcpTool::respondJsonApi() — the empty-list case mirrors the fixture.
 *
 * @internal
 *
 * @coversNothing
 */
final class ReducerFlagsTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;

    /**
     * @covers \FireflyIII\Mcp\Tools\AbstractMcpTool
     * @covers \FireflyIII\Mcp\Support\JsonApiReducer
     */
    public function testGivenIncludeNullsTrueWhenListingTagsThenPreservesNullAttributes(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->seedTag($user, 'travel');

        $response = $this->invokeTool($user, ListTagsTool::class, ['include_nulls' => true]);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertNotEmpty($payload['data']);
        $row = $payload['data'][0];
        self::assertArrayNotHasKey('attributes', $row);
        // TagTransformer emits null `date` for a tag without an explicit date — the reducer
        // must keep the key when include_nulls=true.
        self::assertArrayHasKey('date', $row);
        self::assertNull($row['date']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\AbstractMcpTool
     * @covers \FireflyIII\Mcp\Tools\ListAccountsTool
     */
    public function testGivenVerboseTrueWhenListingAccountsThenAcceptsArgumentAndReturnsEnvelope(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, ListAccountsTool::class, ['type' => 'asset', 'verbose' => true]);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertSame([], $payload['data']);
        // In verbose mode the meta envelope is the raw Fractal output, which still carries
        // the pagination block — proving the verbose path resolved without invoking the reducer.
        self::assertArrayHasKey('pagination', $payload['meta']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\AbstractMcpTool
     */
    public function testGivenVerboseTrueWhenListingTagsThenRetainsTypeAndAttributesWrappers(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->seedTag($user, 'travel');

        $response = $this->invokeTool($user, ListTagsTool::class, ['verbose' => true]);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertNotEmpty($payload['data']);
        $first = $payload['data'][0];
        // Verbose mode keeps the raw JSON:API resource object.
        self::assertSame('tags', $first['type']);
        self::assertIsArray($first['attributes']);
        self::assertSame('travel', $first['attributes']['tag']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }

    private function seedTag(User $user, string $name): void
    {
        Tag::create([
            'user_id'       => $user->id,
            'user_group_id' => $user->user_group_id,
            'tag'           => $name,
            'tag_mode'      => 'nothing'
        ]);
    }
}
