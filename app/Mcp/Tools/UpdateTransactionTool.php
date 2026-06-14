<?php

/*
 * UpdateTransactionTool.php
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

use FireflyIII\Api\V1\Requests\Models\Transaction\UpdateRequest;
use FireflyIII\Mcp\Tools\Concerns\WritesTransactions;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Throwable;

/**
 * Update a single-journal transaction by its group id.
 *
 * Only the fields provided in the request are updated; omitted fields remain
 * untouched. Annotated as `IsDestructive` and `IsIdempotent`.
 */
#[Description('Update an existing transaction. Only fields you pass are changed; others are left alone.')]
#[IsDestructive]
#[IsIdempotent]
final class UpdateTransactionTool extends AbstractMcpTool
{
    use WritesTransactions;

    public function handle(Request $request): Response
    {
        $input         = $request->toArray();
        $transactionId = array_key_exists('transaction_id', $input) ? (int) $input['transaction_id'] : 0;
        if ($transactionId <= 0) {
            return Response::error('transaction_id is required and must be a positive integer.');
        }

        /** @var User $user */
        $user = auth()->user();

        /** @var null|TransactionGroup $group */
        $group = TransactionGroup::where('user_id', $user->id)->find($transactionId);
        if (null === $group) {
            return Response::error(sprintf('Transaction #%d not found.', $transactionId));
        }

        $journal = $group->transactionJournals()->first();
        if (null === $journal) {
            return Response::error(sprintf('Transaction #%d has no journals.', $transactionId));
        }

        $line          = $this->buildUpdateLine($input, (int) $journal->id);
        $payload       = ['transactions' => [$line]];
        $updateRequest = new UpdateRequest();
        if (null !== ($invalid = $this->validateAgainstRules($payload, $updateRequest->rules()))) {
            $this->auditWriteFailure('validation', 'Validation failed');

            return $invalid;
        }

        try {
            $group = app(TransactionGroupRepositoryInterface::class)->update($group, $payload);
        } catch (Throwable $e) {
            $this->auditWriteFailure('repository', $e->getMessage());

            return Response::error(sprintf('Failed to update transaction #%d: %s', $transactionId, $e->getMessage()));
        }

        Log::channel('audit')->info('MCP write', [
            'tool'              => $this->name(),
            'user_id'           => auth()->id(),
            'transaction_id'    => $group->id,
            'idempotent_replay' => false
        ]);

        return $this->respondJsonApi($this->buildGroupDocument($group), $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id'   => $schema->integer()->required()->description('Numeric ID of the transaction group to update.'),
            'amount'           => $schema->string()->description('New positive decimal amount.'),
            'date'             => $schema->string()->description('New ISO-8601 date/time.'),
            'description'      => $schema->string()->description('New description (1-1000 chars).'),
            'source_id'        => $schema->integer()->description('New source account id.'),
            'source_name'      => $schema->string()->description('New source account name.'),
            'destination_id'   => $schema->integer()->description('New destination account id.'),
            'destination_name' => $schema->string()->description('New destination account name.'),
            'category_id'      => $schema->integer()->description('Category id; pass 0 or omit to leave unchanged.'),
            'category_name'    => $schema->string()->description('Category name.'),
            'budget_id'        => $schema->integer()->description('Budget id (only meaningful on withdrawals).'),
            'budget_name'      => $schema->string()->description('Budget name.'),
            'tags'             => $schema->array()->items($schema->string())->description('Replace tags with this set (deduped case-insensitively).'),
            'notes'            => $schema->string()->description('Replace notes (max 32KB).'),
            'verbose'          => $schema->boolean()->description('Return the full JSON:API document instead of the reduced shape.'),
            'include_nulls'    => $schema->boolean()->description('Keep null attribute values in the reduced shape.')
        ];
    }

    /**
     * Build the `transactions.*` line for the existing UpdateRequest, dropping unset fields
     * so the partial-update semantics of `TransactionGroupRepository::update()` are preserved.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function buildUpdateLine(array $input, int $journalId): array
    {
        $line = ['transaction_journal_id' => $journalId];
        $map  = [
            'amount'           => 'string',
            'date'             => 'string',
            'description'      => 'string',
            'source_id'        => 'int',
            'source_name'      => 'string',
            'destination_id'   => 'int',
            'destination_name' => 'string',
            'category_id'      => 'int',
            'category_name'    => 'string',
            'budget_id'        => 'int',
            'budget_name'      => 'string',
            'notes'            => 'string'
        ];
        foreach ($map as $field => $cast) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $line[$field] = 'int' === $cast ? (int) $input[$field] : (string) $input[$field];
        }
        if (array_key_exists('tags', $input)) {
            $tags = $this->normaliseTags($input['tags']);
            if (null !== $tags) {
                $line['tags'] = $tags;
            }
        }

        return $line;
    }
}
