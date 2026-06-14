<?php

/*
 * CreateWithdrawalTool.php
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
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

/**
 * Create a single-journal withdrawal transaction.
 */
#[Description('Create a withdrawal (spend) transaction in the authenticated user\'s ledger.')]
final class CreateWithdrawalTool extends AbstractMcpTool
{
    use WritesTransactions;

    public function handle(Request $request): Response
    {
        return $this->createGroup('withdrawal', $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'source_id'        => $schema->integer()->description('Numeric ID of the source asset/liability account. Provide either source_id or source_name.'),
            'source_name'      => $schema->string()->description('Name of the source asset/liability account (resolved within the user\'s accounts).'),
            'destination_id'   => $schema
                ->integer()
                ->description('Numeric ID of the destination expense account. Provide either destination_id or destination_name.'),
            'destination_name' => $schema->string()->description('Name of the destination expense account (auto-created as Expense if it does not exist).'),
            'amount'           => $schema
                ->string()
                ->required()
                ->description('Positive decimal amount as a string (e.g. "12.50"). Currency follows the source account.'),
            'date'             => $schema->string()->required()->description('ISO-8601 date/time (e.g. "2026-05-17" or "2026-05-17T10:00:00Z").'),
            'description'      => $schema->string()->required()->description('Human-readable transaction description (1-1000 chars).'),
            'category_id'      => $schema->integer()->description('Numeric ID of a category to attach.'),
            'category_name'    => $schema->string()->description('Category name to attach (created if missing).'),
            'budget_id'        => $schema->integer()->description('Numeric ID of a budget to attach (withdrawals only).'),
            'budget_name'      => $schema->string()->description('Budget name to attach.'),
            'tags'             => $schema
                ->array()
                ->items($schema->string())
                ->description('Tag ids (numeric strings) or tag names; case-insensitive duplicates are removed.'),
            'notes'            => $schema->string()->description('Free-form notes attached to the journal (max 32KB).'),
            'idempotency_key'  => $schema
                ->string()
                ->max(self::MAX_IDEMPOTENCY_KEY_LENGTH)
                ->description('Printable-ASCII key (max 128 chars) used to deduplicate retries; replays return the original transaction.'),
            'verbose'          => $schema->boolean()->description('Return the full JSON:API document instead of the reduced shape (default false).'),
            'include_nulls'    => $schema->boolean()->description('Keep null attribute values in the reduced shape (default false).')
        ];
    }
}
