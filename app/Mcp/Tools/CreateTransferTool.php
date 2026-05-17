<?php

/*
 * CreateTransferTool.php
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
 * Create a single-journal transfer between two asset accounts (WIP_MCP §4.5, D-029).
 *
 * Cross-currency transfers are rejected (D-029): the source and destination accounts
 * must share the same currency. Users who need cross-currency must use the REST API.
 */
#[Description('Create a transfer between two of the authenticated user\'s asset accounts (same currency only).')]
final class CreateTransferTool extends AbstractMcpTool
{
    use WritesTransactions;

    public function handle(Request $request): Response
    {
        return $this->createGroup('transfer', $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'source_id'        => $schema->integer()->description('Numeric ID of the source asset account.'),
            'source_name'      => $schema->string()->description('Name of the source asset account (must already exist).'),
            'destination_id'   => $schema->integer()->description('Numeric ID of the destination asset account.'),
            'destination_name' => $schema->string()->description('Name of the destination asset account (must already exist).'),
            'amount'           => $schema->string()->required()->description('Positive decimal amount. Both accounts must share the same currency.'),
            'date'             => $schema->string()->required()->description('ISO-8601 date/time.'),
            'description'      => $schema->string()->required()->description('Human-readable transaction description (1-1000 chars).'),
            'category_id'      => $schema->integer()->description('Numeric ID of a category to attach.'),
            'category_name'    => $schema->string()->description('Category name to attach (created if missing).'),
            'tags'             => $schema->array()->items($schema->string())->description('Tag ids or tag names.'),
            'notes'            => $schema->string()->description('Free-form notes attached to the journal.'),
            'idempotency_key'  => $schema
                ->string()
                ->max(self::MAX_IDEMPOTENCY_KEY_LENGTH)
                ->description('Printable-ASCII key (max 128 chars) used to deduplicate retries.'),
            'verbose'          => $schema->boolean()->description('Return the full JSON:API document instead of the reduced shape.'),
            'include_nulls'    => $schema->boolean()->description('Keep null attribute values in the reduced shape.')
        ];
    }
}
