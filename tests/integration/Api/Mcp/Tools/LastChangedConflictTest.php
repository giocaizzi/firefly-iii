<?php

/*
 * LastChangedConflictTest.php
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
use FireflyIII\Mcp\Tools\ApplyRuleTool;
use FireflyIII\Mcp\Tools\ClassifyTransactionsTool;
use FireflyIII\Mcp\Tools\GetTransactionTool;
use FireflyIII\Mcp\Tools\UpdateTransactionTool;
use FireflyIII\Models\Rule;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Repositories\Rule\RuleRepositoryInterface;
use FireflyIII\User;
use Tests\integration\Api\Mcp\Concerns\CallsApiTools;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * A rule action edits a journal (here its notes) without touching the group's updated_at;
 * LastChanged still reports it, so if_unmodified_since sees the edit.
 *
 * @internal
 *
 * @coversNothing
 */
final class LastChangedConflictTest extends TestCase
{
    use CallsApiTools;
    use EnsuresPassportKeys;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Support\LastChanged
     * @covers \FireflyIII\Mcp\Tools\UpdateTransactionTool
     * @covers \FireflyIII\Mcp\Tools\ClassifyTransactionsTool
     */
    public function testRuleEditAfterTheReadIsAConflict(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');
        $user  = $this->createAuthenticatedUser();
        $group = $this->createWithdrawal($user, $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking'), $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Bakery'));
        $read  = $this->readUpdatedAt($user, $group);

        Carbon::setTestNow('2026-06-01 10:05:00');
        $rule  = $this->notesRule($user);
        $this->okJson($user, ApplyRuleTool::class, ['rule_id' => $rule->id, 'transaction_ids' => [$group->id]]);
        self::assertSame('set by a rule', $group->transactionJournals()->first()?->notes()->first()?->text);
        self::assertSame($read, $group->fresh()?->updated_at->toAtomString(), 'The rule edit bumped the group after all; this test no longer covers the gap.');

        $after = $this->readUpdatedAt($user, $group);
        self::assertSame(Carbon::parse('2026-06-01 10:05:00')->toAtomString(), $after);

        $update = $this->invokeTool($user, UpdateTransactionTool::class, ['transaction_id' => $group->id, 'description' => 'Bread', 'if_unmodified_since' => $read]);
        $update->assertHasErrors(['"error":"conflict"']);

        $classify = $this->okJson($user, ClassifyTransactionsTool::class, ['ids' => [$group->id], 'notes' => 'mine', 'dry_run' => false, 'if_unmodified_since' => [(string) $group->id => $read]]);
        self::assertSame('conflict', $classify['data']['rows'][0]['status']);
        self::assertSame($after, $classify['data']['rows'][0]['updated_at']);

        $this->okJson($user, UpdateTransactionTool::class, ['transaction_id' => $group->id, 'description' => 'Bread', 'if_unmodified_since' => $after]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function notesRule(User $user): Rule
    {
        $repository = app(RuleRepositoryInterface::class);
        $repository->setUser($user);

        return $repository->store([
            'rule_group_id'   => $this->createRuleGroup($user)->id,
            'title'           => 'Notes rule',
            'trigger'         => 'store-journal',
            'strict'          => true,
            'active'          => true,
            'stop_processing' => false,
            'triggers'        => [['type' => 'description_contains', 'value' => 'Lunch', 'active' => true, 'stop_processing' => false]],
            'actions'         => [['type' => 'set_notes', 'value' => 'set by a rule', 'active' => true, 'stop_processing' => false]]
        ]);
    }

    private function readUpdatedAt(User $user, TransactionGroup $group): string
    {
        return $this->okJson($user, GetTransactionTool::class, ['id' => $group->id, 'fields' => ['updated_at']])['data']['updated_at'];
    }
}
