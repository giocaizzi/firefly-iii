<?php

/*
 * DeleteRuleToolTest.php
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

use FireflyIII\Mcp\Tools\DeleteRuleTool;
use FireflyIII\Models\Rule;
use Tests\integration\Api\Mcp\Concerns\AssertsAuditLog;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers DeleteRuleTool — successful delete + audit log line, and the error paths.
 *
 * @internal
 *
 * @coversNothing
 */
final class DeleteRuleToolTest extends TestCase
{
    use AssertsAuditLog;
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\DeleteRuleTool
     */
    public function testGivenExistingRuleWhenDeletingThenRemovedAndAuditLogged(): void
    {
        $user = $this->createAuthenticatedUser();
        $rule = $this->createRule($user);

        $this->captureAuditChannel();

        $response = $this->invokeTool($user, DeleteRuleTool::class, ['rule_id' => $rule->id]);
        $response->assertOk();

        self::assertNull(Rule::find($rule->id), 'Rule should be deleted.');
        $this->assertAuditInfo(
            static fn(string $message, array $ctx): bool => (
                'MCP write' === $message
                && ($ctx['rule_id'] ?? null) === $rule->id
            )
        );
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\DeleteRuleTool
     */
    public function testGivenMissingRuleWhenDeletingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, DeleteRuleTool::class, ['rule_id' => 999_999]);
        $response->assertHasErrors(['not found']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\DeleteRuleTool
     */
    public function testGivenNoIdWhenDeletingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, DeleteRuleTool::class, []);
        $response->assertHasErrors(['rule_id is required']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
