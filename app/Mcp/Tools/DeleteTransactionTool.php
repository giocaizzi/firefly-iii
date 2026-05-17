<?php

/*
 * DeleteTransactionTool.php
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
use Throwable;

/**
 * Soft-delete a transaction group by its id (WIP_MCP §4.5, D-021).
 */
#[Description('Delete a transaction by its id. The journal is soft-deleted (recoverable via the REST API).')]
#[IsDestructive]
final class DeleteTransactionTool extends AbstractMcpTool
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

        try {
            app(TransactionGroupRepositoryInterface::class)->destroy($group);
        } catch (Throwable $e) {
            $this->auditWriteFailure('repository', $e->getMessage());

            return Response::error(sprintf('Failed to delete transaction #%d: %s', $transactionId, $e->getMessage()));
        }

        Log::channel('audit')->info('MCP write', [
            'tool'              => $this->name(),
            'user_id'           => auth()->id(),
            'transaction_id'    => $transactionId,
            'idempotent_replay' => false
        ]);

        return Response::text(sprintf('Deleted transaction #%d.', $transactionId));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->integer()->required()->description('Numeric ID of the transaction group to delete.'),
            'verbose'        => $schema->boolean()->description('Unused for deletes; accepted for argument-parity.'),
            'include_nulls'  => $schema->boolean()->description('Unused for deletes; accepted for argument-parity.')
        ];
    }
}
