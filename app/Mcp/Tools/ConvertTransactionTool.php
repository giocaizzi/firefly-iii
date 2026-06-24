<?php

/*
 * ConvertTransactionTool.php
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
 * Convert a transaction from one type to another (withdrawal/deposit/transfer).
 *
 * Changing type usually means one side of the transaction must change account: a
 * withdrawal→transfer needs an asset destination, a withdrawal→deposit needs a revenue
 * source, etc. Pass the new source/destination accordingly. Split transactions (more
 * than one journal in the group) are not supported.
 */
#[Description('Convert a transaction to a different type (withdrawal, deposit, transfer). Provide the new source/destination accounts the target type requires. Single-journal transactions only.')]
#[IsDestructive]
#[IsIdempotent]
final class ConvertTransactionTool extends AbstractMcpTool
{
    use WritesTransactions;

    private const array TYPES = ['withdrawal', 'deposit', 'transfer'];

    public function handle(Request $request): Response
    {
        $input         = $request->toArray();
        $transactionId = array_key_exists('transaction_id', $input) ? (int) $input['transaction_id'] : 0;
        if ($transactionId <= 0) {
            return Response::error('transaction_id is required and must be a positive integer.');
        }
        $type = array_key_exists('type', $input) ? (string) $input['type'] : '';
        if (!in_array($type, self::TYPES, true)) {
            return Response::error(sprintf('type is required and must be one of: %s.', implode(', ', self::TYPES)));
        }

        /** @var User $user */
        $user  = auth()->user();

        /** @var null|TransactionGroup $group */
        $group = TransactionGroup::where('user_id', $user->id)->find($transactionId);
        if (null === $group) {
            return Response::error(sprintf('Transaction #%d not found.', $transactionId));
        }

        $journals = $group->transactionJournals()->get();
        if ($journals->count() > 1) {
            return Response::error(sprintf('Transaction #%d is a split transaction; conversion is not supported.', $transactionId));
        }
        $journal = $journals->first();
        if (null === $journal) {
            return Response::error(sprintf('Transaction #%d has no journals.', $transactionId));
        }

        $currentType = strtolower((string) $journal->transactionType?->type);
        if ($currentType === $type) {
            return Response::error(sprintf('Transaction #%d is already a %s.', $transactionId, $type));
        }

        $line          = $this->buildConvertLine($input, (int) $journal->id, $type);
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

            return Response::error(sprintf('Failed to convert transaction #%d: %s', $transactionId, $e->getMessage()));
        }

        Log::channel('audit')->info('MCP write', [
            'tool'              => $this->name(),
            'user_id'           => auth()->id(),
            'transaction_id'    => $group->id,
            'converted_to'      => $type,
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
            'transaction_id'   => $schema->integer()->required()->description('Numeric ID of the transaction group to convert.'),
            'type'             => $schema->string()->enum(self::TYPES)->required()->description('Target transaction type.'),
            'source_id'        => $schema->integer()->description('New source account id (required when the target type needs a different source).'),
            'source_name'      => $schema->string()->description('New source account name.'),
            'destination_id'   => $schema->integer()->description('New destination account id (required when the target type needs a different destination).'),
            'destination_name' => $schema->string()->description('New destination account name.'),
            'verbose'          => $schema->boolean()->description('Return the full JSON:API document instead of the reduced shape.'),
            'include_nulls'    => $schema->boolean()->description('Keep null attribute values in the reduced shape.')
        ];
    }

    /**
     * Build the `transactions.*` line carrying the new type and any account changes.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function buildConvertLine(array $input, int $journalId, string $type): array
    {
        $line = [
            'transaction_journal_id' => $journalId,
            'type'                   => $type
        ];
        $map  = [
            'source_id'        => 'int',
            'source_name'      => 'string',
            'destination_id'   => 'int',
            'destination_name' => 'string'
        ];
        foreach ($map as $field => $cast) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $line[$field] = 'int' === $cast ? (int) $input[$field] : (string) $input[$field];
        }

        return $line;
    }
}
