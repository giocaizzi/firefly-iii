<?php

/*
 * UserTagsResourceTest.php
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

use FireflyIII\Mcp\Resources\UserTagsResource;
use FireflyIII\Models\Tag;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\TestCase;

/**
 * Covers WIP_MCP §5.2 UserTagsResource — auth-scoped, flat {id, tag, description}.
 *
 * @internal
 *
 * @coversNothing
 */
final class UserTagsResourceTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;

    /**
     * @covers \FireflyIII\Mcp\Resources\UserTagsResource
     */
    public function testGivenSeededTagWhenReadingUserTagsThenReturnsFlatTagRow(): void
    {
        $user = $this->createAuthenticatedUser();
        Tag::create([
            'user_id'       => $user->id,
            'user_group_id' => $user->user_group_id,
            'tag'           => 'travel',
            'description'   => 'Trips',
            'tag_mode'      => 'nothing'
        ]);

        $response = $this->invokeResource($user, UserTagsResource::class);
        $response->assertOk();

        $payload = $this->decodeJson($response);
        self::assertCount(1, $payload['data']);
        $row = $payload['data'][0];
        self::assertArrayNotHasKey('attributes', $row);
        self::assertSame('travel', $row['tag']);
        self::assertSame('Trips', $row['description']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
