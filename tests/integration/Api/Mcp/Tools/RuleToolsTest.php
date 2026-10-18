<?php

/*
 * RuleToolsTest.php
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

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Mcp\Tools\ApplyRuleTool;
use FireflyIII\Mcp\Tools\CreateRuleGroupTool;
use FireflyIII\Mcp\Tools\CreateRuleTool;
use FireflyIII\Mcp\Tools\TestRuleTool;
use FireflyIII\Mcp\Tools\UpdateRuleGroupTool;
use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\User;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers the rule tooling: create-rule, create-rule-group, update-rule-group, test-rule and apply-rule.
 *
 * @internal
 *
 * @coversNothing
 */
final class RuleToolsTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\CreateRuleTool
     */
    public function testGivenInvalidInputWhenCreatingRuleThenRejectsWithField(): void
    {
        $user  = $this->createAuthenticatedUser();
        $group = $this->createRuleGroup($user, 'Imports');
        $base  = [
            'title'         => 'New rule',
            'rule_group_id' => $group->id,
            'triggers'      => [['type' => 'description_contains', 'value' => 'x']],
            'actions'       => [['type' => 'set_category', 'value' => 'X']]
        ];

        $this->invokeTool($user, CreateRuleTool::class, [
            ...$base,
            'triggers' => [['type' => 'description_has', 'value' => 'x']]
        ])->assertHasErrors(['triggers.0.type', 'Unknown trigger type']);
        $this->invokeTool($user, CreateRuleTool::class, [
            ...$base,
            'actions' => [['type' => 'set_colour', 'value' => 'x']]
        ])->assertHasErrors(['actions.0.type', 'Unknown action type']);
        $this->invokeTool($user, CreateRuleTool::class, [
            ...$base,
            'triggers' => [['type' => 'description_contains']]
        ])->assertHasErrors(['triggers.0.value', 'needs a value']);
        $this->invokeTool($user, CreateRuleTool::class, [
            ...$base,
            'actions' => [['type' => 'add_tag']]
        ])->assertHasErrors(['actions.0.value', 'needs a value']);
        $this->invokeTool($user, CreateRuleTool::class, [
            ...$base,
            'triggers' => [['type' => 'amount_more', 'value' => 'lots']]
        ])->assertHasErrors(['triggers.0.value']);
        $this->invokeTool($user, CreateRuleTool::class, [
            ...$base,
            'actions' => [['type' => 'set_category', 'value' => 'X', 'active' => false]]
        ])->assertHasErrors(['at least one active action']);
        $this->invokeTool($user, CreateRuleTool::class, [...$base, 'triggers' => []])->assertHasErrors(['non-empty list']);
        $this->invokeTool($user, CreateRuleTool::class, [...$base, 'rule_group_id' => 999_999])->assertHasErrors(['Rule group #999999 not found']);
        $this->invokeTool($user, CreateRuleTool::class, [
            'title'            => 'x',
            'rule_group_title' => 'Nope',
            'triggers'         => $base['triggers'],
            'actions'          => $base['actions']
        ])->assertHasErrors(['No rule group titled']);

        $this->invokeTool($user, CreateRuleTool::class, $base)->assertOk();
        $this->invokeTool($user, CreateRuleTool::class, $base)->assertHasErrors(['already exists']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\CreateRuleGroupTool
     * @covers \FireflyIII\Mcp\Tools\UpdateRuleGroupTool
     */
    public function testGivenRuleGroupsWhenCreatingAndUpdatingThenOrderTitleAndActiveChange(): void
    {
        $user  = $this->createAuthenticatedUser();
        $first = $this->createRuleGroup($user, 'First');

        $created = $this->decodeJson($this->invokeTool($user, CreateRuleGroupTool::class, ['title' => 'Second', 'description' => 'More rules']));
        self::assertSame('Second', $created['data']['title']);
        self::assertTrue($created['data']['active']);
        $this->invokeTool($user, CreateRuleGroupTool::class, ['title' => 'Second'])->assertHasErrors(['already exists']);

        $updated = $this->decodeJson($this->invokeTool($user, UpdateRuleGroupTool::class, [
            'rule_group_id' => $created['data']['id'],
            'title'         => 'Renamed',
            'order'         => 1,
            'active'        => false
        ]));
        self::assertSame('Renamed', $updated['data']['title']);
        self::assertFalse($updated['data']['active']);
        self::assertSame(1, $updated['data']['order']);
        self::assertSame(2, (int) RuleGroup::findOrFail($first->id)->order);

        $this->invokeTool($user, UpdateRuleGroupTool::class, ['rule_group_id' => $first->id])->assertHasErrors(['Nothing to update']);
        $this->invokeTool($user, UpdateRuleGroupTool::class, ['rule_group_id' => $first->id, 'title' => 'Renamed'])->assertHasErrors(['already exists']);
        $this->invokeTool($user, UpdateRuleGroupTool::class, ['rule_group_id' => 999_999, 'title' => 'x'])->assertHasErrors(['not found']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ApplyRuleTool
     */
    public function testGivenRuleWhenApplyingThenChangesMatchesAndReportsCount(): void
    {
        [$user, $groups] = $this->seedCoffee();
        $rule = $this->createRule($user, 'Coffee rule');

        $this->invokeTool($user, ApplyRuleTool::class, ['rule_id' => $rule->id])->assertHasErrors(['Pass a scope']);

        $payload = $this->decodeJson($this->invokeTool($user, ApplyRuleTool::class, ['rule_id' => $rule->id, 'transaction_ids' => [$groups[0]->id]]));
        self::assertSame(['rule_ids' => [$rule->id], 'affected' => 1], $payload);
        self::assertSame(1, $this->categorised($user));

        $group = $this->decodeJson($this->invokeTool($user, ApplyRuleTool::class, ['rule_group_id' => $rule->rule_group_id, 'start' => '2026-04-01']));
        self::assertSame(1, $group['affected']);
        self::assertSame(2, $this->categorised($user));
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\TestRuleTool
     */
    public function testGivenRuleWhenTestingThenListsMatchesWithoutChangingThem(): void
    {
        [$user, $groups] = $this->seedCoffee();
        $rule = $this->createRule($user, 'Coffee rule');

        $payload = $this->decodeJson($this->invokeTool($user, TestRuleTool::class, ['rule_id' => $rule->id, 'start' => '2026-04-01', 'end' => '2026-04-30']));
        self::assertSame(2, $payload['meta']['count']);
        self::assertEqualsCanonicalizing([$groups[0]->id, $groups[1]->id], array_column($payload['data'], 'id'));
        self::assertSame(0, $this->categorised($user));

        $byIds = $this->decodeJson($this->invokeTool($user, TestRuleTool::class, [
            'rule_id'         => $rule->id,
            'transaction_ids' => [$groups[1]->id, $groups[2]->id]
        ]));
        self::assertSame(1, $byIds['meta']['count']);
        self::assertSame($groups[1]->id, $byIds['data'][0]['id']);

        $paged = $this->decodeJson($this->invokeTool($user, TestRuleTool::class, ['rule_group_id' => $rule->rule_group_id, 'limit' => 1]));
        self::assertSame(2, $paged['meta']['count']);
        self::assertCount(1, $paged['data']);
        self::assertTrue($paged['meta']['has_more']);

        $this->invokeTool($user, TestRuleTool::class, [])->assertHasErrors(['Pass rule_id or rule_group_id']);
        $this->invokeTool($user, TestRuleTool::class, ['rule_id' => 999_999])->assertHasErrors(['Rule #999999 not found']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\CreateRuleTool
     */
    public function testGivenValidInputWhenCreatingRuleThenStoresTriggersAndActions(): void
    {
        $user  = $this->createAuthenticatedUser();
        $group = $this->createRuleGroup($user, 'Imports');

        $response = $this->invokeTool($user, CreateRuleTool::class, [
            'title'            => 'Coffee',
            'rule_group_title' => 'Imports',
            'strict'           => true,
            'triggers'         => [
                ['type' => 'description_contains', 'value' => 'coffee'],
                ['type' => '-description_contains', 'value' => 'refund'],
                ['type' => 'amount_less', 'value' => '20', 'prohibited' => true],
                ['type' => 'has_no_category']
            ],
            'actions'          => [['type' => 'set_category', 'value' => 'Coffee']]
        ]);
        $response->assertOk();
        $payload = $this->decodeJson($response);

        $rule = Rule::findOrFail($payload['data']['id']);
        self::assertSame($group->id, (int) $rule->rule_group_id);
        self::assertTrue($rule->strict);
        self::assertSame('store-journal', $rule->ruleTriggers()->where('trigger_type', 'user_action')->first()?->trigger_value);
        $types = $rule->ruleTriggers()->where('trigger_type', '!=', 'user_action')->orderBy('order')->pluck('trigger_type')->all();
        self::assertSame(['description_contains', '-description_contains', '-amount_less', 'has_no_category'], $types);
        self::assertSame(['set_category'], $rule->ruleActions()->pluck('action_type')->all());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }

    private function categorised(User $user): int
    {
        return $user->transactionJournals()->whereHas('categories')->count();
    }

    /**
     * Two April coffees and one tea; the seeded rule (createRule) sets category Coffee on "coffee".
     *
     * @return array{0: User, 1: list<TransactionGroup>}
     */
    private function seedCoffee(): array
    {
        $user     = $this->createAuthenticatedUser();
        $checking = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $shop     = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Cafe');
        $groups   = [];
        foreach ([
            ['2026-04-02', 'coffee beans'],
            ['2026-04-09', 'coffee to go'],
            ['2026-04-10', 'tea']
        ] as [$date, $description]) {
            $groups[] = $this->storeTransaction($user, [
                'type'           => TransactionTypeEnum::WITHDRAWAL->value,
                'date'           => Carbon::parse($date),
                'amount'         => '4.00',
                'description'    => $description,
                'source_id'      => $checking->id,
                'destination_id' => $shop->id,
                'reconciled'     => false
            ]);
        }

        return [$user, $groups];
    }
}
