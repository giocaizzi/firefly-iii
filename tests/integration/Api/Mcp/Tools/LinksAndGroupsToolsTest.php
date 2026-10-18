<?php

/*
 * LinksAndGroupsToolsTest.php
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

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Mcp\Tools\BillsWriteTool;
use FireflyIII\Mcp\Tools\LinksReadTool;
use FireflyIII\Mcp\Tools\LinksWriteTool;
use FireflyIII\Mcp\Tools\ObjectGroupsReadTool;
use FireflyIII\Mcp\Tools\ObjectGroupsWriteTool;
use FireflyIII\Models\LinkType;
use FireflyIII\Models\ObjectGroup;
use FireflyIII\Models\TransactionJournalLink;
use FireflyIII\Repositories\User\UserRepositoryInterface;
use Tests\integration\Api\Mcp\Concerns\CallsApiTools;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class LinksAndGroupsToolsTest extends TestCase
{
    use CallsApiTools;
    use EnsuresPassportKeys;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\LinksWriteTool
     */
    public function testLinkTypeRoundTripIsAdminOnly(): void
    {
        $user = $this->createAuthenticatedUser();
        $data = ['name' => 'Split payment', 'inward' => 'is split from', 'outward' => 'splits'];

        $this->invokeTool($user, LinksWriteTool::class, ['action' => 'create', 'kind' => 'type', 'data' => $data])->assertHasErrors();
        app(UserRepositoryInterface::class)->attachRole($user, 'owner');
        $user->load('roles');

        $created = $this->okJson($user, LinksWriteTool::class, ['action' => 'create', 'kind' => 'type', 'data' => $data]);
        $id      = $created['data']['id'];
        $updated = $this->okJson($user, LinksWriteTool::class, ['action' => 'update', 'kind' => 'type', 'id' => $id, 'data' => ['outward' => 'is split into']]);
        self::assertSame('is split into', $updated['data']['outward']);
        self::assertSame('Split payment', $this->okJson($user, LinksReadTool::class, ['action' => 'get', 'kind' => 'type', 'id' => $id])['data']['name']);

        $this->invokeTool($user, LinksWriteTool::class, ['action' => 'delete', 'kind' => 'type', 'id' => $id])->assertHasNoErrors();
        self::assertFalse(LinkType::whereKey($id)->exists());
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ObjectGroupsReadTool
     * @covers \FireflyIII\Mcp\Tools\ObjectGroupsWriteTool
     */
    public function testObjectGroupRoundTrip(): void
    {
        $user = $this->createAuthenticatedUser();
        $bill = $this->okJson($user, BillsWriteTool::class, [
            'action' => 'create',
            'data'   => [
                'name'               => 'Gym',
                'amount_min'         => '20',
                'amount_max'         => '20',
                'date'               => '2026-01-01',
                'repeat_freq'        => 'monthly',
                'object_group_title' => 'Health'
            ]
        ]);

        $groups = $this->okJson($user, ObjectGroupsReadTool::class, ['action' => 'list']);
        self::assertSame(['Health'], array_column($groups['data'], 'title'));
        $id = $groups['data'][0]['id'];

        $bills = $this->okJson($user, ObjectGroupsReadTool::class, ['action' => 'bills', 'id' => $id]);
        self::assertSame([$bill['data']['id']], array_column($bills['data'], 'id'));
        self::assertSame([], $this->okJson($user, ObjectGroupsReadTool::class, ['action' => 'piggy-banks', 'id' => $id])['data']);

        $updated = $this->okJson($user, ObjectGroupsWriteTool::class, ['action' => 'update', 'id' => $id, 'data' => ['title' => 'Health & fitness']]);
        self::assertSame('Health & fitness', $updated['data']['title']);
        self::assertSame('Health & fitness', $this->okJson($user, ObjectGroupsReadTool::class, ['action' => 'get', 'id' => $id])['data']['title']);

        $this->invalidArgument($user, ObjectGroupsWriteTool::class, ['action' => 'create', 'id' => $id], 'action');
        $error = $this->invalidArgument($user, ObjectGroupsWriteTool::class, ['action' => 'delete', 'id' => $id], 'confirm');
        self::assertSame(['subscriptions' => 1], $error['dependants']);
        $this->invokeTool($user, ObjectGroupsWriteTool::class, ['action' => 'delete', 'id' => $id, 'confirm' => true])->assertHasNoErrors();
        self::assertFalse(ObjectGroup::whereKey($id)->exists());
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\LinksReadTool
     * @covers \FireflyIII\Mcp\Tools\LinksWriteTool
     */
    public function testTransactionLinkRoundTrip(): void
    {
        $user    = $this->createAuthenticatedUser();
        $asset   = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $expense = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop');
        $first   = $this->createWithdrawal($user, $asset, $expense, '30', 'Purchase')->transactionJournals()->first();
        $second  = $this->createWithdrawal($user, $asset, $expense, '30', 'Return')->transactionJournals()->first();

        $types = $this->okJson($user, LinksReadTool::class, ['action' => 'list', 'kind' => 'type']);
        self::assertContains('Refund', array_column($types['data'], 'name'));

        $created = $this->okJson($user, LinksWriteTool::class, [
            'action' => 'create',
            'data'   => ['link_type_name' => 'Refund', 'inward_id' => $first->id, 'outward_id' => $second->id]
        ]);
        $id      = $created['data']['id'];
        self::assertSame([(string) $first->id, (string) $second->id], [(string) $created['data']['inward_id'], (string) $created['data']['outward_id']]);

        $byJournal = $this->okJson($user, LinksReadTool::class, ['action' => 'list', 'journal_id' => $second->id]);
        self::assertSame([$id], array_column($byJournal['data'], 'id'));

        $updated = $this->okJson($user, LinksWriteTool::class, ['action' => 'update', 'id' => $id, 'data' => ['notes' => 'partial refund']]);
        self::assertSame('partial refund', $updated['data']['notes']);
        $get = $this->okJson($user, LinksReadTool::class, ['action' => 'get', 'id' => $id]);
        self::assertSame(['partial refund', (string) $first->id, (string) $second->id], [
            $get['data']['notes'],
            (string) $get['data']['inward_id'],
            (string) $get['data']['outward_id']
        ]);

        $this->invalidArgument(
            $user,
            LinksWriteTool::class,
            ['action' => 'create', 'data' => ['link_type_name' => 'Refund', 'inward_id' => $first->id, 'outward_id' => 999_999]],
            'data.outward_id'
        );

        $this->invokeTool($user, LinksWriteTool::class, ['action' => 'delete', 'id' => $id])->assertHasNoErrors();
        self::assertFalse(TransactionJournalLink::whereKey($id)->exists());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
