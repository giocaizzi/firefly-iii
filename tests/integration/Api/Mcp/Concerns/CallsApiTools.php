<?php

/*
 * CallsApiTools.php
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

namespace Tests\integration\Api\Mcp\Concerns;

use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\User;
use PHPUnit\Framework\Assert;

/**
 * Helpers for the API-backed `<area>-read` / `<area>-write` tools.
 */
trait CallsApiTools
{
    use InvokesMcpServer;

    /**
     * A second user in their own group, to prove the tools never reach another user's data.
     */
    protected function createOtherUser(): User
    {
        $group = UserGroup::create(['title' => 'other@email.com']);
        $role  = UserRole::query()->where('title', 'owner')->first();
        $user  = User::create(['email' => 'other@email.com', 'password' => 'password', 'user_group_id' => $group->id]);
        GroupMembership::create(['user_id' => $user->id, 'user_group_id' => $group->id, 'user_role_id' => $role->id]);

        return $user;
    }

    /**
     * Invoke a tool that must fail with an invalid_argument error on $field; returns the error object.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    protected function invalidArgument(User $user, string $tool, array $args, string $field): array
    {
        $response = $this->invokeTool($user, $tool, $args);
        $response->assertHasErrors();
        $error = json_decode($this->responseText($response), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('invalid_argument', $error['error'] ?? null);
        Assert::assertSame($field, $error['field'] ?? null, (string) ($error['message'] ?? ''));

        return $error;
    }

    /**
     * Invoke a tool that must succeed and return its decoded JSON payload.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    protected function okJson(User $user, string $tool, array $args): array
    {
        $response = $this->invokeTool($user, $tool, $args);
        $text     = $this->responseText($response);
        $decoded  = json_decode($text, true);
        Assert::assertIsArray($decoded, $text);
        Assert::assertArrayNotHasKey('error', $decoded, $text);
        $response->assertHasNoErrors();

        return $decoded;
    }
}
