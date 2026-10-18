<?php

/*
 * MatchTransactionsTool.php
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

namespace FireflyIII\Mcp\Tools;

use Carbon\Carbon;
use FireflyIII\Mcp\Support\InvalidArgument;
use FireflyIII\Mcp\Support\TransactionMatcher;
use FireflyIII\Mcp\Tools\Concerns\MatchesTransactions;
use FireflyIII\Mcp\Tools\Concerns\ProjectsTransactions;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

use function Safe\preg_match;

/**
 * Match caller-supplied rows (e.g. lines of a statement the caller already parsed) against stored
 * transactions. See TransactionMatcher for the matching and one-to-one assignment rules.
 *
 * Intended import flow: match-transactions → bulk-create-transactions with dry_run=true and
 * skip_duplicates=true → user confirms → the same call without dry_run → read back.
 */
#[Description(
    'Match structured external rows (date, signed amount, account, optional external_id) to existing transactions, one-to-one: matched|ambiguous|unmatched. Import flow: match, bulk-create dry_run, confirm, write, read back.'
)]
#[IsReadOnly]
#[IsIdempotent]
final class MatchTransactionsTool extends AbstractMcpTool
{
    use MatchesTransactions;
    use ProjectsTransactions;

    public const int MAX_ROWS = 200;

    private const array REFERENCE_FIELDS = ['external_id', 'internal_reference'];

    public function handle(Request $request): Response
    {
        $this->validateShapeArgs($request);
        $raw = $request->get('rows');
        if (!is_array($raw) || [] === $raw || !array_is_list($raw)) {
            InvalidArgument::fail('rows', 'rows must be a non-empty list.');
        }
        if (count($raw) > self::MAX_ROWS) {
            InvalidArgument::fail('rows', sprintf('rows must hold at most %d items.', self::MAX_ROWS), ['max' => self::MAX_ROWS]);
        }

        $window    = $this->dateWindowArg($request);
        $tolerance = null === $request->get('amount_tolerance') ? '0' : $this->decimalArg($request->get('amount_tolerance'), 'amount_tolerance', false);
        $field     = $this->enumArg($request, 'reference_field', self::REFERENCE_FIELDS, 'external_id');
        $types     = $this->matchTypesArg($request);
        $scope     = $this->scopeArg($request);
        $rows      = [];
        foreach ($raw as $index => $row) {
            $rows[] = $this->rowArg($row, $index, $scope, $types);
        }

        /** @var User $user */
        $user    = auth()->user();
        $results = new TransactionMatcher()->match($user, $rows, $window, $tolerance, $field);

        $journalIds = [];
        foreach ($results as $result) {
            foreach ([$result['match'], ...$result['candidates']] as $candidate) {
                if (null !== $candidate) {
                    $journalIds[] = $candidate['journal_id'];
                }
            }
        }
        $groupRows = $this->groupRowsForJournals($journalIds, $request);

        $data   = [];
        $counts = ['matched' => 0, 'ambiguous' => 0, 'unmatched' => 0];
        foreach ($results as $index => $result) {
            ++$counts[$result['status']];
            $entry = ['ref' => $this->rowRef($raw[$index], $index), 'status' => $result['status']];
            if (null !== $result['match']) {
                $entry = [...$entry, ...$this->candidateEntry($result['match'], $groupRows)];
            }
            if ('ambiguous' === $result['status']) {
                $entry['candidates'] = array_map(fn(array $candidate): array => $this->candidateEntry($candidate, $groupRows), $result['candidates']);
                if ($result['candidate_count'] > count($result['candidates'])) {
                    $entry['candidate_count'] = $result['candidate_count'];
                }
            }
            $data[] = $entry;
        }

        return Response::json(['data' => $data, 'meta' => ['primary_currency' => Amount::getPrimaryCurrency()->code, ...$counts]]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $row = $schema->object([
            'ref'         => $schema->string()->description('Caller label echoed back; default the row index.'),
            'date'        => $schema->string()->required()->description('YYYY-MM-DD.'),
            'amount'      => $schema->string()->required()->description('Signed decimal on the account: negative = money out.'),
            'direction'   => $schema->string()->enum(['in', 'out'])->description('Sign for an unsigned amount.'),
            'currency'    => $schema->string()->description('ISO code; also matches the foreign amount.'),
            'account'     => $schema->string()->description('Account id or exact name; overrides the scope.'),
            'description' => $schema->string()->description('Tie-break only.'),
            'external_id' => $schema->string()->description('Matched against reference_field first.')
        ]);

        return [
            'rows'             => $schema->array()->items($row)->min(1)->max(self::MAX_ROWS)->required()->description(sprintf('1-%d rows.', self::MAX_ROWS)),
            'date_window_days' => $schema->integer()->description(sprintf('Max date distance, default 0, max %d.', self::MAX_DATE_WINDOW_DAYS)),
            'account'          => $schema->string()->description('Scope: account id or exact name.'),
            'accounts'         => $schema->array()->items($schema->string())->description('Scope: account ids or names. Default every asset account.'),
            'amount_tolerance' => $schema->string()->description('Max absolute amount difference, default 0.'),
            'types'            => $schema
                ->array()
                ->items($schema->string()->enum(array_keys(self::MATCH_TYPES)))
                ->description('Default withdrawal, deposit, transfer.'),
            'reference_field'  => $schema->string()->enum(self::REFERENCE_FIELDS)->description('Field compared with row external_id, default external_id.'),
            ...$this->transactionOutputSchema($schema)
        ];
    }

    /**
     * @param array<string, mixed>     $candidate
     * @param array<int, mixed>        $groupRows
     *
     * @return array<string, mixed>
     */
    private function candidateEntry(array $candidate, array $groupRows): array
    {
        $entry = ['basis' => $candidate['basis']];
        if ($candidate['journal_id'] !== $candidate['group_id']) {
            $entry['journal_id'] = $candidate['journal_id'];
        }
        $entry['transaction'] = $groupRows[$candidate['group_id']] ?? ['id' => $candidate['group_id']];

        return $entry;
    }

    /**
     * @param list<int>    $scope
     * @param list<string> $types
     *
     * @return array{date: Carbon, amount: string, currency: null|string, account_ids: list<int>, reference: null|string, description: null|string, types: list<string>}
     */
    private function rowArg(mixed $row, int $index, array $scope, array $types): array
    {
        $prefix = sprintf('rows.%d.', $index);
        if (!is_array($row)) {
            InvalidArgument::fail(sprintf('rows.%d', $index), 'Each row must be an object.');
        }
        $date = $this->dateArg(new Request([$prefix . 'date' => $row['date'] ?? null]), $prefix . 'date');
        if (null === $date) {
            InvalidArgument::fail($prefix . 'date', $prefix . 'date is required.');
        }
        if (!array_key_exists('amount', $row)) {
            InvalidArgument::fail($prefix . 'amount', $prefix . 'amount is required.');
        }
        $amount    = $this->decimalArg($row['amount'], $prefix . 'amount', true);
        $direction = $this->enumArg(new Request([$prefix . 'direction' => $row['direction'] ?? null]), $prefix . 'direction', ['in', 'out'], '');
        if ('' !== $direction) {
            $amount = ('out' === $direction ? '-' : '') . ltrim($amount, '-');
        }

        $currency = null;
        if (null !== ($row['currency'] ?? null)) {
            $currency = is_string($row['currency']) ? strtoupper(trim($row['currency'])) : '';
            if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
                InvalidArgument::fail($prefix . 'currency', $prefix . 'currency must be a 3-letter ISO code.', ['received' => $row['currency']]);
            }
        }

        $reference = $row['external_id'] ?? null;
        if (null !== $reference && !is_string($reference) && !is_int($reference)) {
            InvalidArgument::fail($prefix . 'external_id', $prefix . 'external_id must be a string.', ['received' => $reference]);
        }
        $description = $row['description'] ?? null;

        return [
            'date'        => $date,
            'amount'      => $amount,
            'currency'    => $currency,
            'account_ids' => null === ($row['account'] ?? null) ? $scope : [$this->accountRefId($row['account'], $prefix . 'account')],
            'reference'   => null === $reference ? null : (string) $reference,
            'description' => is_string($description) ? $description : null,
            'types'       => $types
        ];
    }

    private function rowRef(mixed $row, int $index): int|string
    {
        $ref = is_array($row) ? $row['ref'] ?? null : null;

        return is_string($ref) || is_int($ref) ? $ref : $index;
    }

    /**
     * Batch account scope from `account` and/or `accounts`; default every asset account.
     *
     * @return list<int>
     */
    private function scopeArg(Request $request): array
    {
        $ids = [];
        if (null !== $request->get('account')) {
            $ids[] = $this->accountRefId($request->get('account'), 'account');
        }
        $accounts = $request->get('accounts');
        if (null !== $accounts) {
            if (!is_array($accounts)) {
                InvalidArgument::fail('accounts', 'accounts must be a list of account ids or names.', ['received' => $accounts]);
            }
            foreach (array_values($accounts) as $position => $account) {
                $ids[] = $this->accountRefId($account, sprintf('accounts.%d', $position));
            }
        }

        return [] === $ids ? $this->assetAccountIds() : array_values(array_unique($ids));
    }
}
