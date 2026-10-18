<?php

/*
 * ArgumentValidationTest.php
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
use FireflyIII\Mcp\Tools\CreateWithdrawalTool;
use FireflyIII\Mcp\Tools\ListAccountsTool;
use FireflyIII\Mcp\Tools\ListTagsTool;
use FireflyIII\Mcp\Tools\ListTransactionsTool;
use FireflyIII\Mcp\Tools\SearchTransactionsTool;
use FireflyIII\Models\TransactionJournal;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers strict argument validation: out-of-range paging, unknown enum values, malformed
 * dates and output arguments are rejected with a machine-readable error instead of being
 * silently coerced.
 *
 * @internal
 *
 * @coversNothing
 */
final class ArgumentValidationTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    /**
     * @return iterable<string, array{0: class-string, 1: array<string, mixed>, 2: string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'limit above max' => [ListTransactionsTool::class, ['limit' => 201], 'limit'];
        yield 'limit zero' => [ListTagsTool::class, ['limit' => 0], 'limit'];
        yield 'page zero' => [ListAccountsTool::class, ['page' => 0], 'page'];
        yield 'page not an integer' => [ListTagsTool::class, ['page' => 'two'], 'page'];
        yield 'unknown tx type' => [ListTransactionsTool::class, ['type' => 'zzz'], 'type'];
        yield 'unknown account type' => [ListAccountsTool::class, ['type' => 'zzz'], 'type'];
        yield 'invalid date' => [ListTransactionsTool::class, ['start' => '2026-13-45'], 'start'];
        yield 'non-date string' => [SearchTransactionsTool::class, ['query' => 'x', 'end' => 'tomorrow'], 'end'];
        yield 'start after end' => [ListTransactionsTool::class, ['start' => '2026-02-01', 'end' => '2026-01-01'], 'start'];
        yield 'unknown account name' => [ListTransactionsTool::class, ['account_name' => 'Nope'], 'account_name'];
        yield 'negative desc cap' => [ListTransactionsTool::class, ['description_max' => -1], 'description_max'];
        yield 'fields not a list' => [ListTransactionsTool::class, ['fields' => 5], 'fields'];
    }

    /**
     * @param class-string         $tool
     * @param array<string, mixed> $args
     *
     * @covers \FireflyIII\Mcp\Support\InvalidArgument
     * @covers \FireflyIII\Mcp\Tools\AbstractMcpTool
     */
    #[DataProvider('invalidArguments')]
    public function testGivenInvalidArgumentWhenCallingToolThenReturnsMachineReadableError(string $tool, array $args, string $field): void
    {
        $user     = $this->createAuthenticatedUser();
        $response = $this->invokeTool($user, $tool, $args);
        $response->assertHasErrors();

        $error = json_decode($this->responseText($response), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('invalid_argument', $error['error']);
        self::assertSame($field, $error['field']);
        self::assertNotEmpty($error['message']);
    }

    /**
     * Output arguments are validated before the write, so a bad one never leaves a half-done call.
     *
     * @covers \FireflyIII\Mcp\Tools\Concerns\ProjectsTransactions
     */
    public function testGivenInvalidOutputArgumentWhenCreatingThenRejectsBeforeWriting(): void
    {
        $user   = $this->createAuthenticatedUser();
        $source = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $before = TransactionJournal::count();

        $response = $this->invokeTool($user, CreateWithdrawalTool::class, [
            'source_id'        => $source->id,
            'destination_name' => 'Bakery',
            'amount'           => '1.00',
            'date'             => '2026-05-17',
            'description'      => 'Bun',
            'description_max'  => -5
        ]);

        $response->assertHasErrors(['description_max']);
        self::assertSame($before, TransactionJournal::count());
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\AbstractMcpTool
     */
    public function testGivenMaxLimitWhenListingThenAccepts(): void
    {
        $user    = $this->createAuthenticatedUser();
        $payload = $this->decodeJson($this->invokeTool($user, ListTagsTool::class, ['limit' => 200, 'page' => '1']));

        self::assertSame(200, $payload['meta']['pagination']['per_page']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
