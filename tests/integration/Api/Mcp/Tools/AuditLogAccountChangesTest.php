<?php

/*
 * AuditLogAccountChangesTest.php
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
use FireflyIII\Mcp\Tools\CreateRuleTool;
use FireflyIII\Mcp\Tools\GetAuditLogTool;
use FireflyIII\Repositories\AuditLogEntry\ALERepositoryInterface;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers the `before` value of source/destination account changes in get-audit-log: recorded
 * by the rule actions, inferred for older entries that were stored without it.
 *
 * @internal
 *
 * @coversNothing
 */
final class AuditLogAccountChangesTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\GetAuditLogTool
     */
    public function testGivenAccountChangesWhenReadingAuditLogThenBeforeIsFilled(): void
    {
        $user     = $this->createAuthenticatedUser();
        $checking = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $this->createAccount($user, AccountTypeEnum::ASSET, 'Savings');
        $shop  = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop');
        $tx    = $this->createWithdrawal($user, $checking, $shop, '3.00', 'coffee');
        $group = $this->createRuleGroup($user, 'Accounts');

        $created = $this->decodeJson($this->invokeTool($user, CreateRuleTool::class, [
            'title'         => 'Move',
            'rule_group_id' => $group->id,
            'triggers'      => [['type' => 'description_is', 'value' => 'coffee']],
            'actions'       => [['type' => 'set_source_account', 'value' => 'Savings']]
        ]));
        $this->invokeTool($user, ApplyRuleTool::class, ['rule_id' => $created['data']['id'], 'transaction_ids' => [$tx->id]])->assertOk();

        $log   = $this->decodeJson($this->invokeTool($user, GetAuditLogTool::class, ['transaction_id' => $tx->id]));
        $entry = $this->entry($log['entries'], 'set_source');
        self::assertSame('Checking', $entry['before']);
        self::assertSame('Savings', $entry['after']);
        self::assertArrayNotHasKey('before_inferred', $entry);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\GetAuditLogTool
     */
    public function testGivenLegacyAccountEntriesWhenReadingAuditLogThenBeforeIsInferred(): void
    {
        $user     = $this->createAuthenticatedUser();
        $checking = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $shop     = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop');
        $tx       = $this->createWithdrawal($user, $checking, $shop, '3.00', 'coffee');
        $journal  = $tx->transactionJournals()->firstOrFail();
        $repo     = app(ALERepositoryInterface::class);
        foreach (['Cafe A', 'Cafe B'] as $index => $after) {
            $entry = $repo->store([
                'auditable' => $journal,
                'changer'   => $user,
                'action'    => 'set_destination',
                'before'    => null,
                'after'     => $after
            ]);
            $entry->created_at = Carbon::parse('2026-05-01')->addMinutes($index);
            $entry->save();
        }

        $log = $this->decodeJson($this->invokeTool($user, GetAuditLogTool::class, ['transaction_id' => $tx->id]));

        self::assertNull($log['entries'][0]['before']);
        self::assertArrayNotHasKey('before_inferred', $log['entries'][0]);
        self::assertSame('Cafe A', $log['entries'][1]['before']);
        self::assertTrue($log['entries'][1]['before_inferred']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return array<string, mixed>
     */
    private function entry(array $entries, string $action): array
    {
        foreach ($entries as $entry) {
            if ($action === $entry['action']) {
                return $entry;
            }
        }
        self::fail(sprintf('No "%s" entry in the audit log.', $action));
    }
}
