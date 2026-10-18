<?php

/*
 * ClassifyTransactionsTool.php
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
use FireflyIII\Api\V1\Requests\Models\Transaction\UpdateRequest;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Mcp\Support\CreatedEntities;
use FireflyIII\Mcp\Support\InvalidArgument;
use FireflyIII\Mcp\Tools\Concerns\EditsClassification;
use FireflyIII\Mcp\Tools\Concerns\GuardsConcurrentEdits;
use FireflyIII\Mcp\Tools\Concerns\GuardsNameCreation;
use FireflyIII\Models\Account;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\Support\Search\SearchInterface;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Throwable;

use function Safe\json_encode;

/**
 * Bulk classification edits with a preview.
 *
 * Only category, budget, bill, tags, notes and the expense (withdrawal destination) or revenue
 * (deposit source) account change; amounts, dates, currencies, asset accounts, descriptions and
 * types are out of reach because any other argument is rejected. Targets are group `ids` or a
 * search `query`, capped at MAX_TARGETS. `dry_run` defaults to true and returns before → after per
 * row; `dry_run=false` writes each changed group in its own DB transaction and reports per-row
 * status. `if_unmodified_since` maps group ids to the `updated_at` the caller read; a newer change
 * is a per-row conflict and is not written.
 */
#[Description(
    'Set category/budget/bill/tags/notes/expense-revenue account on up to 200 transactions (ids or query). dry_run (default true) previews before/after.'
)]
#[IsDestructive(false)]
final class ClassifyTransactionsTool extends AbstractMcpTool
{
    use EditsClassification;
    use GuardsConcurrentEdits;
    use GuardsNameCreation;

    public const int DEFAULT_QUERY_LIMIT = 50;

    public const int MAX_TARGETS = 200;

    private const array ALLOWED_ARGUMENTS = [
        'ids',
        'query',
        'limit',
        'dry_run',
        'create_missing',
        'if_unmodified_since',
        'category_id',
        'category_name',
        'clear_category',
        'budget_id',
        'budget_name',
        'clear_budget',
        'bill_id',
        'bill_name',
        'clear_bill',
        'tags',
        'add_tags',
        'remove_tags',
        'notes',
        'append_notes',
        'source_name',
        'destination_name'
    ];

    private const array CHANGE_ARGUMENTS = [
        'category_id',
        'category_name',
        'clear_category',
        'budget_id',
        'budget_name',
        'clear_budget',
        'bill_id',
        'bill_name',
        'clear_bill',
        'tags',
        'add_tags',
        'remove_tags',
        'notes',
        'append_notes',
        'source_name',
        'destination_name'
    ];

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user  = auth()->user();
        $input = $request->toArray();
        foreach (array_keys($input) as $key) {
            if (!in_array($key, self::ALLOWED_ARGUMENTS, true)) {
                InvalidArgument::fail((string) $key, sprintf('classify-transactions does not accept "%s"; it only changes classification fields.', $key), [
                    'allowed' => self::ALLOWED_ARGUMENTS
                ]);
            }
        }
        if ([] === array_intersect(self::CHANGE_ARGUMENTS, array_keys($input))) {
            InvalidArgument::fail('category_name', 'Pass at least one change (category, budget, bill, tags, notes, source_name or destination_name).');
        }

        $dryRun        = (bool) $request->get('dry_run', true);
        $createMissing = $this->createMissingArg($request);
        $change        = $this->resolveChange($user, $input);
        $since         = $this->sinceMap($input['if_unmodified_since'] ?? null);
        $ids           = $this->targetIds($user, $request, $input);

        $missing = $this->missingNames($user, TransactionTypeEnum::WITHDRAWAL->value, [
            'category_name' => $change['category']['create'] ?? null,
            'tags'          => [...($change['tags'] ?? []), ...$change['add_tags']]
        ]);
        foreach (['source', 'destination'] as $direction) {
            if (null !== ($change[$direction]['create'] ?? null)) {
                $missing['accounts'][] = $change[$direction]['create'];
            }
        }
        if (!$createMissing && [] !== $missing) {
            return $this->missingNamesError($missing);
        }

        $groups = TransactionGroup::where('user_id', $user->id)
            ->whereIn('id', $ids)
            ->with([
                'transactionJournals.transactionType',
                'transactionJournals.tags',
                'transactionJournals.categories',
                'transactionJournals.budgets',
                'transactionJournals.bill',
                'transactionJournals.notes'
            ])
            ->get()
            ->keyBy('id');
        $mark    = CreatedEntities::mark($user);
        $rows    = [];
        $counts  = ['changed' => 0, 'unchanged' => 0, 'conflict' => 0, 'error' => 0, 'not_found' => 0];
        $counted = $dryRun ? 'would_change' : 'changed';

        foreach ($ids as $id) {
            /** @var null|TransactionGroup $group */
            $group = $groups->get($id);
            $row   = null === $group ? ['id' => $id, 'status' => 'not_found'] : $this->classifyGroup($group, $change, $since[$id] ?? null, $dryRun);
            ++$counts[$row['status'] === $counted ? 'changed' : $row['status']];
            $rows[] = $row;
        }

        if (!$dryRun) {
            Log::channel('audit')->info('MCP bulk write', [
                'tool'    => $this->name(),
                'user_id' => auth()->id(),
                'count'   => count($ids),
                'changed' => $counts['changed']
            ]);
        }

        $summary = ['dry_run' => $dryRun, 'matched' => count($ids), $counted => $counts['changed']];
        unset($counts['changed']);
        $result = ['data' => [...$summary, ...array_filter($counts, static fn(int $count): bool => $count > 0), 'rows' => $rows]];
        $meta   = $dryRun ? ([] === $missing ? [] : ['would_create' => $missing]) : $this->createdMeta($mark);
        if ([] !== $meta) {
            $result['meta'] = $meta;
        }

        return Response::json($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ids'                 => $schema
                ->array()
                ->items($schema->integer())
                ->max(self::MAX_TARGETS)
                ->description(sprintf('Group ids, max %d (or query).', self::MAX_TARGETS)),
            'query'               => $schema->string()->description('Search query (or ids). Syntax: resource firefly-iii://help/search.'),
            'limit'               => $schema
                ->integer()
                ->description(sprintf('Query cap, default %d, max %d; more matches = error.', self::DEFAULT_QUERY_LIMIT, self::MAX_TARGETS)),
            'dry_run'             => $schema->boolean()->description('false = apply.'),
            'category_id'         => $schema->integer()->description('Category id.'),
            'category_name'       => $schema->string()->description('Category name.'),
            'budget_id'           => $schema->integer()->description('Budget id (withdrawals).'),
            'budget_name'         => $schema->string()->description('Exact budget name (withdrawals).'),
            'bill_id'             => $schema->integer()->description('Bill id (withdrawals).'),
            'bill_name'           => $schema->string()->description('Exact bill name (withdrawals).'),
            'tags'                => $schema->array()->items($schema->string())->description('Replaces all tags.'),
            'notes'               => $schema->string()->description('Replaces notes ("" clears).'),
            'append_notes'        => $schema->string()->description('Appended on a new line.'),
            'destination_name'    => $schema->string()->description('Expense account of withdrawals.'),
            'source_name'         => $schema->string()->description('Revenue account of deposits.'),
            'if_unmodified_since' => $schema->object()->description('{"<id>": "<updated_at>"}; newer = conflict.'),
            ...$this->classificationEditSchema($schema),
            ...$this->createMissingSchema($schema)
        ];
    }

    /**
     * Before / after values of the classification fields of one journal.
     *
     * @return array<string, mixed>
     */
    private function classification(TransactionJournal $journal): array
    {
        /** @var null|Transaction $source */
        $source = $journal->transactions()->where('amount', '<', 0)->with('account')->first();

        /** @var null|Transaction $destination */
        $destination = $journal->transactions()->where('amount', '>', 0)->with('account')->first();

        return [
            'category'    => $journal->categories()->first()?->name,
            'budget'      => $journal->budgets()->first()?->name,
            'bill'        => $journal->bill()->first()?->name,
            'tags'        => $this->journalTags($journal),
            'notes'       => $journal->notes()->first()?->text,
            'source'      => $source?->account?->name,
            'destination' => $destination?->account?->name
        ];
    }

    /**
     * Plan (and, unless dry-run, apply) the change on one group.
     *
     * @param array<string, mixed> $change
     *
     * @return array<string, mixed>
     */
    private function classifyGroup(TransactionGroup $group, array $change, null|Carbon $since, bool $dryRun): array
    {
        if (null !== $since && null !== ($conflict = $this->editConflict($group, $since))) {
            return ['id' => $group->id, 'status' => 'conflict', 'updated_at' => $conflict['updated_at'], 'message' => $conflict['message']];
        }

        $lines   = [];
        $splits  = [];
        $changed = false;
        foreach ($group->transactionJournals as $journal) {
            $type = $journal->transactionType->type;
            if (null !== ($error = $this->typeError($type, $change))) {
                return ['id' => $group->id, 'status' => 'error', 'message' => $error];
            }
            $before = $this->classification($journal);
            [$line, $after] = $this->planJournal($journal, $before, $change);
            $diff     = array_keys(array_filter($after, static fn(mixed $value, string $key): bool => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));
            $changed  = $changed || [] !== $diff;
            $lines[]  = $line;
            $splits[] = [
                'journal_id' => $journal->id,
                'before'     => array_intersect_key($before, array_flip($diff)),
                'after'      => array_intersect_key($after, array_flip($diff))
            ];
        }

        if (!$changed) {
            return ['id' => $group->id, 'status' => 'unchanged'];
        }
        $row = ['id' => $group->id, 'status' => $dryRun ? 'would_change' : 'changed'];
        $row = 1 === count($splits) ? [...$row, 'before' => $splits[0]['before'], 'after' => $splits[0]['after']] : [...$row, 'splits' => $splits];
        if ($dryRun) {
            return $row;
        }

        $payload   = ['transactions' => $lines];
        $validator = Validator::make($payload, new UpdateRequest()->rules());
        if ($validator->fails()) {
            return ['id' => $group->id, 'status' => 'error', 'message' => json_encode($validator->errors()->toArray())];
        }

        try {
            DB::transaction(static function () use ($group, $payload): void {
                app(TransactionGroupRepositoryInterface::class)->update($group, $payload);
            });
        } catch (Throwable $e) {
            Log::channel('audit')->error('MCP write failed', [
                'tool'      => $this->name(),
                'user_id'   => auth()->id(),
                'stage'     => 'repository',
                'exception' => $e->getMessage()
            ]);

            return ['id' => $group->id, 'status' => 'error', 'message' => $e->getMessage()];
        }
        Log::channel('audit')->info('MCP write', [
            'tool'              => $this->name(),
            'user_id'           => auth()->id(),
            'transaction_id'    => $group->id,
            'idempotent_replay' => false
        ]);

        return $row;
    }

    /**
     * @return list<string>
     */
    private function normaliseTagList(mixed $raw, string $field): array
    {
        if (!is_array($raw)) {
            InvalidArgument::fail($field, sprintf('%s must be a list of tag names.', $field), ['received' => $raw]);
        }
        $tags = [];
        foreach ($raw as $tag) {
            $tag = is_string($tag) || is_int($tag) ? trim((string) $tag) : '';
            if ('' !== $tag) {
                $tags[] = $tag;
            }
        }

        return $this->mergeTags([], $tags, []);
    }

    /**
     * Update line (with only the changed fields) and the expected after-values for one journal.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $change
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function planJournal(TransactionJournal $journal, array $before, array $change): array
    {
        $line  = ['transaction_journal_id' => $journal->id];
        $after = $before;
        foreach (['category', 'budget', 'bill'] as $relation) {
            if (!array_key_exists($relation, $change)) {
                continue;
            }
            $after[$relation] = $change[$relation]['name'];
            if ($after[$relation] !== $before[$relation]) {
                $line = [...$line, ...$change[$relation]['line']];
            }
        }
        foreach (['source', 'destination'] as $direction) {
            if (array_key_exists($direction, $change) && $change[$direction]['name'] !== $before[$direction]) {
                $after[$direction] = $change[$direction]['name'];
                $line              = [...$line, ...$change[$direction]['line']];
            }
        }
        if (null !== $change['tags'] || [] !== $change['add_tags'] || [] !== $change['remove_tags']) {
            $after['tags'] = $this->mergeTags($change['tags'] ?? $before['tags'], $change['add_tags'], $change['remove_tags']);
            if ($after['tags'] !== $before['tags']) {
                $line['tags'] = $after['tags'];
            }
        }
        if (null !== $change['notes'] || null !== $change['append_notes']) {
            $notes =
                $change['notes']
                ?? (null === $before['notes'] || '' === $before['notes'] ? $change['append_notes'] : $before['notes'] . "\n" . $change['append_notes']);
            $after['notes'] = '' === $notes ? null : $notes;
            if ($after['notes'] !== $before['notes']) {
                $line['notes'] = (string) $notes;
            }
        }

        return [$line, $after];
    }

    /**
     * Resolve the requested change once for all targets: ids and names are checked here, never per row.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function resolveChange(User $user, array $input): array
    {
        $cleared = $this->clearedRelations($input);
        $change  = [
            'tags'         => array_key_exists('tags', $input) ? $this->normaliseTagList($input['tags'], 'tags') : null,
            'add_tags'     => $this->normaliseTagList($input['add_tags'] ?? [], 'add_tags'),
            'remove_tags'  => $this->normaliseTagList($input['remove_tags'] ?? [], 'remove_tags'),
            'notes'        => array_key_exists('notes', $input) ? (string) $input['notes'] : null,
            'append_notes' => array_key_exists('append_notes', $input) ? (string) $input['append_notes'] : null
        ];
        if (null !== $change['notes'] && null !== $change['append_notes']) {
            InvalidArgument::fail('append_notes', 'Pass notes (replace) or append_notes, not both.');
        }

        foreach (['category' => $user->categories(), 'budget' => $user->budgets(), 'bill' => $user->bills()] as $relation => $query) {
            if (array_key_exists(sprintf('%s_id', $relation), $cleared)) {
                $change[$relation] = ['name' => null, 'line' => [sprintf('%s_id', $relation) => null, sprintf('%s_name', $relation) => null]];

                continue;
            }
            $id   = (int) ($input[sprintf('%s_id', $relation)] ?? 0);
            $name = trim((string) ($input[sprintf('%s_name', $relation)] ?? ''));
            if ($id > 0) {
                $model = $query->find($id);
                if (null === $model) {
                    InvalidArgument::fail(sprintf('%s_id', $relation), sprintf('%s #%d not found.', ucfirst($relation), $id));
                }
                $change[$relation] = ['name' => $model->name, 'line' => [sprintf('%s_id', $relation) => $model->id]];

                continue;
            }
            if ('' === $name) {
                continue;
            }
            $model = $query->where('name', $name)->first();
            if (null !== $model) {
                $change[$relation] = ['name' => $model->name, 'line' => [sprintf('%s_id', $relation) => $model->id]];

                continue;
            }
            if ('category' !== $relation) {
                InvalidArgument::fail(sprintf('%s_name', $relation), sprintf('No %s named "%s"; %ss are never created here.', $relation, $name, $relation));
            }
            $change['category'] = ['name' => $name, 'line' => ['category_name' => $name], 'create' => $name];
        }

        /** @var AccountRepositoryInterface $accounts */
        $accounts = app(AccountRepositoryInterface::class);
        $accounts->setUser($user);
        $sides = [
            'destination' => [AccountTypeEnum::EXPENSE->value, TransactionTypeEnum::WITHDRAWAL->value],
            'source'      => [AccountTypeEnum::REVENUE->value, TransactionTypeEnum::DEPOSIT->value]
        ];
        foreach ($sides as $direction => [$accountType, $journalType]) {
            $name = trim((string) ($input[sprintf('%s_name', $direction)] ?? ''));
            if ('' === $name) {
                continue;
            }
            $account = $accounts->findByName($name, [$accountType]);
            if ($account instanceof Account) {
                $change[$direction] = ['name' => $account->name, 'line' => [sprintf('%s_id', $direction) => $account->id]];

                continue;
            }

            /** @var list<string> $types */
            $types = config(sprintf('firefly.expected_source_types.%s.%s', $direction, $journalType), []);
            if (null !== $accounts->findByName($name, $types)) {
                InvalidArgument::fail(
                    sprintf('%s_name', $direction),
                    sprintf('"%s" names an account of another type; only %s names can be set here.', $name, strtolower($accountType))
                );
            }
            $change[$direction] = ['name' => $name, 'line' => [sprintf('%s_name', $direction) => $name], 'create' => $name];
        }

        return $change;
    }

    /**
     * @return array<int, Carbon>
     */
    private function sinceMap(mixed $raw): array
    {
        if (null === $raw) {
            return [];
        }
        if (!is_array($raw) || array_is_list($raw) && [] !== $raw) {
            InvalidArgument::fail('if_unmodified_since', 'if_unmodified_since must be an object mapping transaction ids to updated_at.', ['received' => $raw]);
        }
        $result = [];
        foreach ($raw as $id => $value) {
            $since = $this->unmodifiedSince($value, sprintf('if_unmodified_since.%s', $id));
            if (null !== $since) {
                $result[(int) $id] = $since;
            }
        }

        return $result;
    }

    /**
     * Group ids from `ids` or `query` (exactly one), deduplicated and capped.
     *
     * @param array<string, mixed> $input
     *
     * @return list<int>
     */
    private function targetIds(User $user, Request $request, array $input): array
    {
        $hasIds = array_key_exists('ids', $input) && null !== $input['ids'];
        $query  = trim((string) ($input['query'] ?? ''));
        if ($hasIds === ('' !== $query)) {
            InvalidArgument::fail('ids', 'Pass either ids or query.');
        }
        if ($hasIds) {
            if (!is_array($input['ids']) || [] === $input['ids']) {
                InvalidArgument::fail('ids', 'ids must be a non-empty list of transaction ids.', ['received' => $input['ids']]);
            }
            $ids = [];
            foreach ($input['ids'] as $id) {
                if (is_string($id) && ctype_digit($id)) {
                    $id = (int) $id;
                }
                if (!is_int($id) || $id < 1) {
                    InvalidArgument::fail('ids', 'ids must be positive integers.', ['received' => $id]);
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_TARGETS) {
                InvalidArgument::fail('ids', sprintf('At most %d ids per call.', self::MAX_TARGETS), ['max' => self::MAX_TARGETS]);
            }

            return array_values($ids);
        }

        $limit = $this->positiveIntArg($request, 'limit', self::DEFAULT_QUERY_LIMIT);
        if ($limit > self::MAX_TARGETS) {
            InvalidArgument::fail('limit', sprintf('limit must be at most %d.', self::MAX_TARGETS), ['max' => self::MAX_TARGETS]);
        }
        $searcher = app(SearchInterface::class);
        $searcher->setUser($user);
        $searcher->parseQuery($query);
        $searcher->setPage(1);
        $searcher->setLimit($limit);
        $groups = $searcher->searchTransactions();
        if ([] !== $searcher->getInvalidOperators()) {
            InvalidArgument::fail('query', 'The query has unknown or invalid operators; fix it before classifying.', [
                'invalid' => $searcher->getInvalidOperators()
            ]);
        }
        if ($groups->total() > $limit) {
            InvalidArgument::fail(
                'query',
                sprintf(
                    'The query matches %d transactions, more than limit %d; narrow it or raise limit (max %d).',
                    $groups->total(),
                    $limit,
                    self::MAX_TARGETS
                ),
                ['matched' => $groups->total(), 'limit' => $limit]
            );
        }

        return array_values(array_unique(array_map(static fn(array $group): int => (int) $group['id'], $groups->getCollection()->all())));
    }

    /**
     * A change that does not apply to this journal type, or null.
     *
     * @param array<string, mixed> $change
     */
    private function typeError(string $type, array $change): null|string
    {
        $withdrawal = TransactionTypeEnum::WITHDRAWAL->value === $type;
        foreach (['budget', 'bill'] as $relation) {
            if (array_key_exists($relation, $change) && null !== $change[$relation]['name'] && !$withdrawal) {
                return sprintf('A %s applies to withdrawals only; this is a %s.', $relation, $type);
            }
        }
        if (array_key_exists('destination', $change) && !$withdrawal) {
            return sprintf('destination_name sets the expense account of withdrawals only; this is a %s.', $type);
        }
        if (array_key_exists('source', $change) && TransactionTypeEnum::DEPOSIT->value !== $type) {
            return sprintf('source_name sets the revenue account of deposits only; this is a %s.', $type);
        }

        return null;
    }
}
