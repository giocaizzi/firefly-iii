<?php

/*
 * UpdateRuleToolTest.php
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

use FireflyIII\Mcp\Tools\UpdateRuleTool;
use FireflyIII\Models\Rule;
use Tests\integration\Api\Mcp\Concerns\AssertsAuditLog;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers UpdateRuleTool — meta-field + trigger/action update, validation, and error paths.
 *
 * @internal
 *
 * @coversNothing
 */
final class UpdateRuleToolTest extends TestCase
{
    use AssertsAuditLog;
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\UpdateRuleTool
     */
    public function testGivenExistingRuleWhenUpdatingThenFieldsChangeAndAuditLogged(): void
    {
        $user = $this->createAuthenticatedUser();
        $rule = $this->createRule($user, 'Original title');

        $this->captureAuditChannel();

        $response = $this->invokeTool($user, UpdateRuleTool::class, [
            'rule_id' => $rule->id,
            'title'   => 'Renamed rule',
            'active'  => false
        ]);
        $response->assertOk();

        $fresh = Rule::find($rule->id);
        self::assertSame('Renamed rule', $fresh?->title);
        self::assertFalse((bool) $fresh?->active);

        $this->assertAuditInfo(
            static fn(string $message, array $ctx): bool => (
                'MCP write' === $message
                && ($ctx['rule_id'] ?? null) === $rule->id
            )
        );
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\UpdateRuleTool
     */
    public function testGivenNewTriggersWhenUpdatingThenTriggersReplaced(): void
    {
        $user = $this->createAuthenticatedUser();
        $rule = $this->createRule($user);

        $response = $this->invokeTool($user, UpdateRuleTool::class, [
            'rule_id'  => $rule->id,
            'triggers' => [
                ['type' => 'description_contains', 'value' => 'tea']
            ]
        ]);
        $response->assertOk();

        $values = $rule->ruleTriggers()->where('trigger_type', 'description_contains')->pluck('trigger_value')->all();
        self::assertSame(['tea'], $values);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\UpdateRuleTool
     */
    public function testGivenInvalidTriggerTypeWhenUpdatingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $rule     = $this->createRule($user);
        $response = $this->invokeTool($user, UpdateRuleTool::class, [
            'rule_id'  => $rule->id,
            'triggers' => [
                ['type' => 'not_a_real_trigger', 'value' => 'x']
            ]
        ]);
        $response->assertHasErrors(['triggers.0.type']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\UpdateRuleTool
     */
    public function testGivenMissingRuleWhenUpdatingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, UpdateRuleTool::class, ['rule_id' => 999_999, 'title' => 'x']);
        $response->assertHasErrors(['not found']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\UpdateRuleTool
     */
    public function testGivenNoFieldsWhenUpdatingThenRespondsWithError(): void
    {
        $user     = $this->createAuthenticatedUser();
        $rule     = $this->createRule($user);
        $response = $this->invokeTool($user, UpdateRuleTool::class, ['rule_id' => $rule->id]);
        $response->assertHasErrors(['Nothing to update']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
