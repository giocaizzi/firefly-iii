<?php

/*
 * MatchTransactionsToolTest.php
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
use FireflyIII\Mcp\Tools\MatchTransactionsTool;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\User;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Covers MatchTransactionsTool — reference and amount/date/account matching, one-to-one
 * assignment, account scoping, currency handling and argument rejection.
 *
 * @internal
 *
 * @coversNothing
 */
final class MatchTransactionsToolTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;
    use SeedsFireflyData;

    private Account $checking;

    private Account $shop;

    private User $user;

    /**
     * @covers \FireflyIII\Mcp\Tools\MatchTransactionsTool
     */
    public function testGivenDateOffsetWhenMatchingThenOnlyMatchesInsideTheWindow(): void
    {
        $group = $this->spend('12.50', '2026-03-01');
        $rows  = [['ref' => 'a', 'date' => '2026-03-03', 'amount' => '-12.5']];

        $sameDay = $this->match(['rows' => $rows]);
        self::assertSame('unmatched', $sameDay['data'][0]['status']);
        self::assertArrayNotHasKey('transaction', $sameDay['data'][0]);

        $windowed = $this->match(['rows' => $rows, 'date_window_days' => 2]);
        self::assertSame('matched', $windowed['data'][0]['status']);
        self::assertSame(['by' => 'amount', 'account_id' => $this->checking->id, 'date_distance_days' => 2], $windowed['data'][0]['basis']);
        self::assertSame($group->id, $windowed['data'][0]['transaction']['id']);
        self::assertSame('12.5', $windowed['data'][0]['transaction']['amount']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\MatchTransactionsTool
     */
    public function testGivenDistinctBestCandidatesWhenMatchingThenAssignsOneToOne(): void
    {
        $early = $this->spend('5.00', '2026-03-01');
        $late  = $this->spend('5.00', '2026-03-03');

        $data = $this->match([
            'date_window_days' => 3,
            'rows'             => [['ref' => 'late', 'date' => '2026-03-03', 'amount' => '-5'], ['ref' => 'early', 'date' => '2026-03-01', 'amount' => '-5']]
        ]);

        self::assertSame(['matched', 'matched'], array_column($data['data'], 'status'));
        self::assertSame($late->id, $data['data'][0]['transaction']['id']);
        self::assertSame($early->id, $data['data'][1]['transaction']['id']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\MatchTransactionsTool
     */
    public function testGivenForeignAmountWhenMatchingWithCurrencyThenComparesTheMatchingAmount(): void
    {
        $usd   = TransactionCurrency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'enabled' => true]);
        $group = $this->spend('10.00', '2026-03-01', ['foreign_currency_id' => $usd->id, 'foreign_amount' => '11.00']);

        $foreign = $this->match(['rows' => [['date' => '2026-03-01', 'amount' => '-11', 'currency' => 'usd']]]);
        self::assertSame('matched', $foreign['data'][0]['status']);
        self::assertSame('foreign_amount', $foreign['data'][0]['basis']['amount_field']);
        self::assertSame($group->id, $foreign['data'][0]['transaction']['id']);

        $native = $this->match(['rows' => [['date' => '2026-03-01', 'amount' => '-10', 'currency' => 'EUR']]]);
        self::assertSame('matched', $native['data'][0]['status']);
        self::assertArrayNotHasKey('amount_field', $native['data'][0]['basis']);

        $other = $this->match(['rows' => [['date' => '2026-03-01', 'amount' => '-10', 'currency' => 'GBP']]]);
        self::assertSame('unmatched', $other['data'][0]['status']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\MatchTransactionsTool
     */
    public function testGivenInternalReferenceFieldWhenMatchingThenComparesThatField(): void
    {
        $group = $this->spend('10.00', '2026-03-01', ['internal_reference' => 'INT-7']);

        $data = $this->match(['reference_field' => 'internal_reference', 'rows' => [['date' => '2026-03-01', 'amount' => '-1', 'external_id' => 'INT-7']]]);

        self::assertSame('matched', $data['data'][0]['status']);
        self::assertSame(['by' => 'internal_reference'], $data['data'][0]['basis']);
        self::assertSame($group->id, $data['data'][0]['transaction']['id']);
        self::assertSame(0, $data['data'][0]['ref']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\MatchTransactionsTool
     */
    public function testGivenInvalidRowsWhenMatchingThenRejectsWithTheField(): void
    {
        $this->invokeTool($this->user, MatchTransactionsTool::class, ['rows' => []])->assertHasErrors(['"field":"rows"']);
        $this->invokeTool($this->user, MatchTransactionsTool::class, [
            'rows' => [['date' => '2026-03-01', 'amount' => 'ten']]
        ])->assertHasErrors(['"field":"rows.0.amount"']);
        $this->invokeTool($this->user, MatchTransactionsTool::class, [
            'rows' => [['date' => '2026-03-01', 'amount' => '1', 'account' => 'Nope']]
        ])->assertHasErrors(['"field":"rows.0.account"']);
        $this->invokeTool($this->user, MatchTransactionsTool::class, ['rows' => [['amount' => '1']]])->assertHasErrors(['"field":"rows.0.date"']);
        $this->invokeTool($this->user, MatchTransactionsTool::class, [
            'rows'             => [['date' => '2026-03-01', 'amount' => '1']],
            'date_window_days' => 500
        ])->assertHasErrors(['"field":"date_window_days"']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\MatchTransactionsTool
     */
    public function testGivenRowWithKnownExternalIdWhenMatchingThenMatchesOnReferenceRegardlessOfAmount(): void
    {
        $group = $this->spend('10.00', '2026-03-01', ['external_id' => 'ABC-1']);

        $data = $this->match(['rows' => [['ref' => 'r1', 'date' => '2026-03-20', 'amount' => '-99', 'external_id' => 'ABC-1']]]);

        self::assertSame('r1', $data['data'][0]['ref']);
        self::assertSame('matched', $data['data'][0]['status']);
        self::assertSame(['by' => 'external_id'], $data['data'][0]['basis']);
        self::assertSame($group->id, $data['data'][0]['transaction']['id']);
        self::assertSame(1, $data['meta']['matched']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\MatchTransactionsTool
     */
    public function testGivenSameAmountOnTwoAccountsWhenScopingThenOnlyTheScopedAccountMatches(): void
    {
        $savings    = $this->createAccount($this->user, AccountTypeEnum::ASSET, 'Savings');
        $onChecking = $this->spend('7.00', '2026-03-01');
        $onSavings  = $this->spend('7.00', '2026-03-01', [], $savings);
        $rows       = [['date' => '2026-03-01', 'amount' => '-7']];

        self::assertSame('ambiguous', $this->match(['rows' => $rows])['data'][0]['status'], 'Default scope is every asset account.');

        $byName = $this->match(['rows' => $rows, 'account' => 'Checking']);
        self::assertSame('matched', $byName['data'][0]['status']);
        self::assertSame($onChecking->id, $byName['data'][0]['transaction']['id']);

        $byRow = $this->match(['rows' => [['date' => '2026-03-01', 'amount' => '-7', 'account' => (string) $savings->id]], 'accounts' => ['Checking']]);
        self::assertSame($onSavings->id, $byRow['data'][0]['transaction']['id'], 'A row account overrides the batch scope.');
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\MatchTransactionsTool
     */
    public function testGivenToleranceWhenMatchingThenReportsTheDifference(): void
    {
        $this->spend('12.50', '2026-03-01');
        $rows = [['date' => '2026-03-01', 'amount' => '-12.40']];

        self::assertSame('unmatched', $this->match(['rows' => $rows])['data'][0]['status']);

        $data = $this->match(['rows' => $rows, 'amount_tolerance' => '0.1']);
        self::assertSame('matched', $data['data'][0]['status']);
        self::assertSame('0.1', $data['data'][0]['basis']['amount_difference']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\MatchTransactionsTool
     */
    public function testGivenTwoRowsAndTwoIdenticalTransactionsWhenMatchingThenBothAreAmbiguous(): void
    {
        $first  = $this->spend('5.00', '2026-03-01');
        $second = $this->spend('5.00', '2026-03-01');

        $data = $this->match(['rows' => [['date' => '2026-03-01', 'amount' => '-5'], ['date' => '2026-03-01', 'amount' => '-5']]]);

        foreach ($data['data'] as $entry) {
            self::assertSame('ambiguous', $entry['status']);
            self::assertArrayNotHasKey('transaction', $entry);
            $ids = array_map(static fn(array $candidate): int => $candidate['transaction']['id'], $entry['candidates']);
            sort($ids);
            self::assertSame([$first->id, $second->id], $ids);
        }
        self::assertSame(2, $data['meta']['ambiguous']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\MatchTransactionsTool
     */
    public function testGivenTwoRowsCompetingForOneTransactionWhenMatchingThenNeitherClaimsIt(): void
    {
        $this->spend('5.00', '2026-03-01');

        $data = $this->match(['rows' => [['date' => '2026-03-01', 'amount' => '-5'], ['date' => '2026-03-01', 'amount' => '-5']]]);

        self::assertSame(['ambiguous', 'ambiguous'], array_column($data['data'], 'status'));
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\MatchTransactionsTool
     */
    public function testGivenTypesFilterWhenMatchingThenOtherTypesAreIgnored(): void
    {
        $this->spend('9.00', '2026-03-01');

        $data = $this->match(['rows' => [['date' => '2026-03-01', 'amount' => '-9']], 'types' => ['deposit']]);

        self::assertSame('unmatched', $data['data'][0]['status']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\MatchTransactionsTool
     */
    public function testGivenUnsignedAmountWithDirectionWhenMatchingThenAppliesTheSign(): void
    {
        $this->spend('8.00', '2026-03-01');

        $out = $this->match(['rows' => [['date' => '2026-03-01', 'amount' => '8', 'direction' => 'out']]]);
        $in  = $this->match(['rows' => [['date' => '2026-03-01', 'amount' => '8']]]);

        self::assertSame('matched', $out['data'][0]['status']);
        self::assertSame('unmatched', $in['data'][0]['status'], 'A positive amount is money in and must not match a withdrawal.');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
        $this->user     = $this->createAuthenticatedUser();
        $this->checking = $this->createAccount($this->user, AccountTypeEnum::ASSET, 'Checking');
        $this->shop     = $this->createAccount($this->user, AccountTypeEnum::EXPENSE, 'Shop');
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function match(array $args): array
    {
        $response = $this->invokeTool($this->user, MatchTransactionsTool::class, $args);
        $response->assertHasNoErrors();

        return $this->decodeJson($response);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function spend(string $amount, string $date, array $extra = [], null|Account $source = null): TransactionGroup
    {
        return $this->storeTransaction($this->user, [
            'type'             => TransactionTypeEnum::WITHDRAWAL->value,
            'date'             => Carbon::parse($date),
            'amount'           => $amount,
            'description'      => 'Purchase',
            'source_id'        => ($source ?? $this->checking)->id,
            'destination_id'   => $this->shop->id,
            'destination_name' => $this->shop->name,
            'reconciled'       => false,
            ...$extra
        ]);
    }
}
