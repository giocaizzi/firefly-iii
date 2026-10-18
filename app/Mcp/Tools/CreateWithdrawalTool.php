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

use FireflyIII\Mcp\Tools\Concerns\ProjectsTransactions;
use FireflyIII\Mcp\Tools\Concerns\WritesTransactions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Create a single-journal withdrawal transaction.
 */
#[Description('Create a withdrawal (expense).')]
#[IsDestructive(false)]
final class CreateWithdrawalTool extends AbstractMcpTool
{
    use ProjectsTransactions;
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
            'source_id'        => $schema->integer()->description('Source asset/liability account id (or source_name).'),
            'source_name'      => $schema->string()->description('Source account name.'),
            'destination_id'   => $schema->integer()->description('Expense account id (or destination_name).'),
            'destination_name' => $schema->string()->description('Expense account name (created if missing).'),
            'amount'           => $schema->string()->required()->description('Positive decimal string, e.g. "12.50".'),
            'date'             => $schema->string()->required()->description('YYYY-MM-DD or ISO 8601 date-time.'),
            'description'      => $schema->string()->required()->description('1-1000 chars.'),
            'category_id'      => $schema->integer()->description('Category id.'),
            'category_name'    => $schema->string()->description('Category name (created if missing).'),
            'budget_id'        => $schema->integer()->description('Budget id.'),
            'budget_name'      => $schema->string()->description('Budget name.'),
            'tags'             => $schema->array()->items($schema->string())->description('Tag names or ids.'),
            'notes'            => $schema->string()->description('Notes.'),
            ...$this->optionalLineSchema($schema),
            'idempotency_key'  => $schema
                ->string()
                ->max(self::MAX_IDEMPOTENCY_KEY_LENGTH)
                ->description('Retry dedup key (printable ASCII, max 128); a replay returns the original.'),
            ...$this->createMissingSchema($schema),
            ...$this->writeOutputSchema($schema)
        ];
    }
}
