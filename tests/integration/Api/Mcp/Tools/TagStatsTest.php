<?php

/*
 * TagStatsTest.php
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
use FireflyIII\Mcp\Tools\GetTagTool;
use FireflyIII\Mcp\Tools\ListTagsTool;
use FireflyIII\Models\Tag;
use FireflyIII\User;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers `with_stats` on list-tags and get-tag, and that the default output has no stats.
 *
 * @internal
 *
 * @coversNothing
 */
final class TagStatsTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\ListTagsTool
     */
    public function testGivenNoStatsFlagWhenListingThenRowsHaveNoStats(): void
    {
        $user = $this->seedTagged();

        $payload = $this->decodeJson($this->invokeTool($user, ListTagsTool::class, []));
        foreach ($payload['data'] as $row) {
            self::assertArrayNotHasKey('stats', $row);
        }
        $single = $this->decodeJson($this->invokeTool($user, GetTagTool::class, ['tag' => 'trip']));
        self::assertArrayNotHasKey('stats', $single['data']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\GetTagTool
     */
    public function testGivenTaggedTransactionsWhenGettingTagWithStatsThenReturnsCountDatesAndSums(): void
    {
        $user = $this->seedTagged();
        $tag  = Tag::where('user_id', $user->id)->where('tag', 'trip')->firstOrFail();

        $payload = $this->decodeJson($this->invokeTool($user, GetTagTool::class, ['id' => $tag->id, 'with_stats' => true]));

        self::assertSame(
            [
                'count'      => 3,
                'first_date' => '2026-03-01',
                'last_date'  => '2026-03-20',
                'totals'     => [['currency' => 'EUR', 'count' => 3, 'withdrawal' => '45.50', 'deposit' => '5.00', 'net' => '-40.50']]
            ],
            $payload['data']['stats']
        );
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\ListTagsTool
     */
    public function testGivenTagsWhenListingWithStatsThenEveryRowHasStats(): void
    {
        $user = $this->seedTagged();

        $payload = $this->decodeJson($this->invokeTool($user, ListTagsTool::class, ['with_stats' => true]));
        $stats   = array_column(
            array_map(static fn(array $row): array => ['tag' => $row['tag'], 'count' => $row['stats']['count']], $payload['data']),
            'count',
            'tag'
        );
        ksort($stats);

        self::assertSame(['empty' => 0, 'other' => 1, 'trip' => 3], $stats);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }

    private function seedTagged(): User
    {
        $user     = $this->createAuthenticatedUser();
        $checking = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $shop     = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Shop');
        $refunds  = $this->createAccount($user, AccountTypeEnum::REVENUE, 'Refunds');
        $rows     = [
            [TransactionTypeEnum::WITHDRAWAL, '2026-03-01', '40.00', $checking->id, $shop->id, ['trip']],
            [TransactionTypeEnum::WITHDRAWAL, '2026-03-10', '5.50', $checking->id, $shop->id, ['trip', 'other']],
            [TransactionTypeEnum::DEPOSIT, '2026-03-20', '5.00', $refunds->id, $checking->id, ['trip']]
        ];
        foreach ($rows as [$type, $date, $amount, $source, $destination, $tags]) {
            $this->storeTransaction($user, [
                'type'           => $type->value,
                'date'           => Carbon::parse($date),
                'amount'         => $amount,
                'description'    => 'tagged',
                'source_id'      => $source,
                'destination_id' => $destination,
                'tags'           => $tags,
                'reconciled'     => false
            ]);
        }
        Tag::create(['user_id' => $user->id, 'user_group_id' => $user->user_group_id, 'tag' => 'empty', 'tag_mode' => 'nothing']);

        return $user;
    }
}
