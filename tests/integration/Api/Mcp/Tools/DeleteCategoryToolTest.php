<?php

/*
 * DeleteCategoryToolTest.php
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

use FireflyIII\Mcp\Tools\DeleteCategoryTool;
use FireflyIII\Models\Category;
use Tests\integration\Api\Mcp\Concerns\AssertsAuditLog;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers DeleteCategoryTool — successful delete + audit log line, and the error paths.
 *
 * @internal
 *
 * @coversNothing
 */
final class DeleteCategoryToolTest extends TestCase
{
    use AssertsAuditLog;
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\DeleteCategoryTool
     */
    public function testGivenExistingCategoryWhenDeletingThenRemovedAndAuditLogged(): void
    {
        $user     = $this->createAuthenticatedUser();
        $category = $this->createCategory($user, 'Disposable');

        $this->captureAuditChannel();

        $response = $this->invokeTool($user, DeleteCategoryTool::class, ['category_id' => $category->id]);
        $response->assertOk();

        self::assertNull(Category::find($category->id), 'Category should be deleted.');
        $this->assertAuditInfo(
            static fn(string $message, array $ctx): bool => (
                'MCP write' === $message
                && ($ctx['category_id'] ?? null) === $category->id
            )
        );
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\DeleteCategoryTool
     */
    public function testGivenMissingCategoryWhenDeletingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, DeleteCategoryTool::class, ['category_id' => 999_999]);
        $response->assertHasErrors(['not found']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\DeleteCategoryTool
     */
    public function testGivenNoIdWhenDeletingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, DeleteCategoryTool::class, []);
        $response->assertHasErrors(['category_id is required']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
