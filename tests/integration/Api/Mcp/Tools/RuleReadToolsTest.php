<?php

/*
 * RuleReadToolsTest.php
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

use FireflyIII\Mcp\Tools\GetRuleTool;
use FireflyIII\Mcp\Tools\ListRuleGroupsTool;
use FireflyIII\Mcp\Tools\ListRulesTool;
use FireflyIII\Mcp\Tools\UpdateRuleTool;
use Illuminate\Support\Facades\DB;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers the compact list-rules row and its filters, get-rule, list-rule-groups, and the
 * HTML-entity decoding of rule text (Rule::description is stored escaped).
 *
 * @internal
 *
 * @coversNothing
 */
final class RuleReadToolsTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\UpdateRuleTool
     */
    public function testGivenEscapedDescriptionWhenUpdatingThenStoresItEscapedOnce(): void
    {
        $user = $this->createAuthenticatedUser();
        $rule = $this->createRule($user, 'Food');

        $payload = $this->decodeJson($this->invokeTool($user, UpdateRuleTool::class, ['rule_id' => $rule->id, 'description' => 'A &amp; B']));

        self::assertSame('A & B', $payload['data']['description']);
        self::assertSame('A &amp; B', DB::table('rules')->where('id', $rule->id)->value('description'));
    }

    /**
     * @covers \FireflyIII\Mcp\Support\HtmlText
     * @covers \FireflyIII\Mcp\Tools\Concerns\ShapesRules
     */
    public function testGivenEscapedRuleTextWhenReadingThenDecodesUnlessVerbose(): void
    {
        $user              = $this->createAuthenticatedUser();
        $rule              = $this->createRule($user, 'Food');
        $rule->description = 'Food & drinks';
        $rule->save();
        // simulate a value that was escaped twice (an escaped value written back)
        DB::table('rules')->where('id', $rule->id)->update(['title' => 'Food &amp;amp; drinks']);

        $payload = $this->decodeJson($this->invokeTool($user, GetRuleTool::class, ['rule_id' => $rule->id]));
        self::assertSame('Food & drinks', $payload['data']['title']);
        self::assertSame('Food & drinks', $payload['data']['description']);

        $verbose = $this->decodeJson($this->invokeTool($user, GetRuleTool::class, ['rule_id' => $rule->id, 'verbose' => true]));
        self::assertSame('Food &amp; drinks', $verbose['data']['attributes']['description']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ListRuleGroupsTool
     */
    public function testGivenRuleGroupWhenListingGroupsThenReturnsCompactRows(): void
    {
        $user  = $this->createAuthenticatedUser();
        $group = $this->createRuleGroup($user, 'Bills & utilities');

        $payload = $this->decodeJson($this->invokeTool($user, ListRuleGroupsTool::class, []));

        self::assertSame([['id' => (int) $group->id, 'title' => 'Bills & utilities', 'active' => true, 'order' => 1]], $payload['data']);
        self::assertFalse($payload['meta']['has_more']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ListRulesTool
     */
    public function testGivenRulesWhenListingThenReturnsCompactRowsAndFilters(): void
    {
        $user   = $this->createAuthenticatedUser();
        $coffee = $this->createRule($user, 'Coffee shops');
        $other  = $this->createRule($user, 'Rent');

        $payload = $this->decodeJson($this->invokeTool($user, ListRulesTool::class, []));
        self::assertCount(2, $payload['data']);
        $row = $payload['data'][0];
        self::assertSame(['id', 'group_id', 'group', 'title', 'active', 'strict', 'order', 'trigger_count', 'action_count'], array_keys($row));
        self::assertSame(1, $row['trigger_count']);
        self::assertSame(1, $row['action_count']);

        $byTitle = $this->decodeJson($this->invokeTool($user, ListRulesTool::class, ['query' => 'coffee']));
        self::assertSame([(int) $coffee->id], array_column($byTitle['data'], 'id'));

        $byGroup = $this->decodeJson($this->invokeTool($user, ListRulesTool::class, ['group_id' => $other->rule_group_id]));
        self::assertSame([(int) $other->id], array_column($byGroup['data'], 'id'));

        $full = $this->decodeJson($this->invokeTool($user, ListRulesTool::class, ['compact' => false]));
        self::assertArrayHasKey('triggers', $full['data'][0]);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\GetRuleTool
     */
    public function testGivenRuleWhenGettingThenReturnsTriggersAndActions(): void
    {
        $user = $this->createAuthenticatedUser();
        $rule = $this->createRule($user, 'Coffee');

        $payload = $this->decodeJson($this->invokeTool($user, GetRuleTool::class, ['rule_id' => $rule->id]));

        self::assertSame((int) $rule->id, $payload['data']['id']);
        self::assertSame('description_contains', $payload['data']['triggers'][0]['type']);
        self::assertSame('set_category', $payload['data']['actions'][0]['type']);

        $this->invokeTool($user, GetRuleTool::class, ['rule_id' => 999_999])->assertHasErrors(['not found']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
