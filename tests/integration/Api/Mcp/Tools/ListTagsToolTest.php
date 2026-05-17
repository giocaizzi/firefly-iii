<?php

/*
 * ListTagsToolTest.php
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

use FireflyIII\Mcp\Tools\ListTagsTool;
use FireflyIII\Models\Tag;
use FireflyIII\User;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\TestCase;

/**
 * Covers WIP_MCP §4.1 ListTagsTool. TagTransformer needs no enrichment so the happy path
 * asserts the seeded tag flattens into the reduced envelope (no `type` / `attributes` wrapper).
 *
 * @internal
 *
 * @coversNothing
 */
final class ListTagsToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;

    /**
     * @covers \FireflyIII\Mcp\Tools\ListTagsTool
     */
    public function testGivenLimitArgWhenListingTagsThenRespectsPagination(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->seedTag($user, 'travel');
        $this->seedTag($user, 'work');
        $this->seedTag($user, 'food');

        $response = $this->invokeTool($user, ListTagsTool::class, ['limit' => 2]);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertCount(2, $payload['data']);
        self::assertSame(2, $payload['meta']['pagination']['per_page']);
        self::assertSame(3, $payload['meta']['pagination']['total']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ListTagsTool
     */
    public function testGivenSeededTagWhenListingThenReturnsFlatTagInData(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->seedTag($user, 'travel');

        $response = $this->invokeTool($user, ListTagsTool::class, []);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertCount(1, $payload['data']);
        $first = $payload['data'][0];
        self::assertSame('travel', $first['tag']);
        self::assertArrayNotHasKey('type', $first);
        self::assertArrayNotHasKey('attributes', $first);
        self::assertSame(1, $payload['meta']['pagination']['total']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }

    private function seedTag(User $user, string $name): Tag
    {
        return Tag::create([
            'user_id'       => $user->id,
            'user_group_id' => $user->user_group_id,
            'tag'           => $name,
            'tag_mode'      => 'nothing'
        ]);
    }
}
