<?php

/*
 * RecurrencesToolsTest.php
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
use FireflyIII\Mcp\Tools\RecurrencesReadTool;
use FireflyIII\Mcp\Tools\RecurrencesWriteTool;
use FireflyIII\Models\Recurrence;
use Tests\integration\Api\Mcp\Concerns\CallsApiTools;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class RecurrencesToolsTest extends TestCase
{
    use CallsApiTools;
    use EnsuresPassportKeys;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\RecurrencesWriteTool
     */
    public function testInvalidRecurrenceIsRejected(): void
    {
        $user = $this->createAuthenticatedUser();

        $error = $this->invokeTool($user, RecurrencesWriteTool::class, ['action' => 'create', 'data' => ['type' => 'withdrawal', 'title' => 'Broken']]);
        $error->assertHasErrors();
        $payload = json_decode($this->responseText($error), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('invalid_argument', $payload['error']);
        self::assertStringStartsWith('data.', $payload['field']);
        self::assertSame(0, Recurrence::count());
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\RecurrencesReadTool
     * @covers \FireflyIII\Mcp\Tools\RecurrencesWriteTool
     */
    public function testRecurrenceRoundTripAndTrigger(): void
    {
        $user    = $this->createAuthenticatedUser();
        $asset   = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $expense = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Landlord');

        $first   = now()->addDay()->format('Y-m-d');
        $lines   = [['description' => 'Rent', 'amount' => '800', 'source_id' => $asset->id, 'destination_id' => $expense->id]];
        $created = $this->okJson($user, RecurrencesWriteTool::class, [
            'action' => 'create',
            'data'   => [
                'type'         => 'withdrawal',
                'title'        => 'Rent',
                'first_date'   => $first,
                'repeat_until' => now()->addYear()->format('Y-m-d'),
                'repetitions'  => [['type' => 'daily', 'moment' => '']],
                'transactions' => $lines
            ]
        ]);
        $id = $created['data']['id'];
        self::assertSame('Rent', $created['data']['title']);

        $get = $this->okJson($user, RecurrencesReadTool::class, ['action' => 'get', 'id' => $id]);
        self::assertNotEmpty($get['data']['repetitions'][0]['occurrences'], 'next occurrences are listed');

        // the API requires the transactions on every update
        $this->invalidArgument(
            $user,
            RecurrencesWriteTool::class,
            ['action' => 'update', 'id' => $id, 'data' => ['title' => 'Flat rent']],
            'data.transactions'
        );
        $updated = $this->okJson($user, RecurrencesWriteTool::class, [
            'action' => 'update',
            'id'     => $id,
            'data'   => ['title' => 'Flat rent', 'transactions' => $lines]
        ]);
        self::assertSame('Flat rent', $updated['data']['title']);

        $this->invalidArgument($user, RecurrencesWriteTool::class, ['action' => 'trigger', 'id' => $id], 'date');
        $triggered = $this->okJson($user, RecurrencesWriteTool::class, ['action' => 'trigger', 'id' => $id, 'date' => $first]);
        self::assertCount(1, $triggered['data']);

        $transactions = $this->okJson($user, RecurrencesReadTool::class, ['action' => 'transactions', 'id' => $id]);
        self::assertSame(array_column($triggered['data'], 'id'), array_column($transactions['data'], 'id'));

        $this->invalidArgument($user, RecurrencesWriteTool::class, ['action' => 'delete', 'id' => $id], 'confirm');
        $this->invokeTool($user, RecurrencesWriteTool::class, ['action' => 'delete', 'id' => $id, 'confirm' => true])->assertHasNoErrors();
        self::assertFalse(Recurrence::whereKey($id)->exists());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
