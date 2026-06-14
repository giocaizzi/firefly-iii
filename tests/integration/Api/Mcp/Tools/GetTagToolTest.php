<?php

/*
 * GetTagToolTest.php
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

use FireflyIII\Mcp\Tools\GetTagTool;
use FireflyIII\Models\Tag;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\TestCase;

/**
 * Covers GetTagTool. Happy path resolves by id and asserts the reduced flat
 * object. Also exercises the lookup-by-name filter argument.
 *
 * @internal
 *
 * @coversNothing
 */
final class GetTagToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;

    /**
     * @covers \FireflyIII\Mcp\Tools\GetTagTool
     */
    public function testGivenMissingTagWhenGettingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, GetTagTool::class, ['id' => 999_999]);
        $response->assertHasErrors(['Tag not found']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\GetTagTool
     */
    public function testGivenSeededTagWhenGettingByIdThenReturnsFlatObject(): void
    {
        $user = $this->createAuthenticatedUser();
        $tag  = Tag::create([
            'user_id'       => $user->id,
            'user_group_id' => $user->user_group_id,
            'tag'           => 'travel',
            'tag_mode'      => 'nothing'
        ]);

        $response = $this->invokeTool($user, GetTagTool::class, ['id' => $tag->id]);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertSame($tag->id, $payload['data']['id']);
        self::assertSame('travel', $payload['data']['tag']);
        self::assertArrayNotHasKey('type', $payload['data']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\GetTagTool
     */
    public function testGivenTagNameFilterWhenGettingThenResolvesByName(): void
    {
        $user = $this->createAuthenticatedUser();
        Tag::create([
            'user_id'       => $user->id,
            'user_group_id' => $user->user_group_id,
            'tag'           => 'foodie',
            'tag_mode'      => 'nothing'
        ]);

        $response = $this->invokeTool($user, GetTagTool::class, ['tag' => 'foodie']);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertSame('foodie', $payload['data']['tag']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
