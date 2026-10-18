<?php

/*
 * EditsClassification.php
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

namespace FireflyIII\Mcp\Tools\Concerns;

use FireflyIII\Mcp\Support\InvalidArgument;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Tag merging and explicit clearing of category / budget / bill, shared by the transaction edit tools.
 *
 * `tags` replaces the set, `add_tags` / `remove_tags` merge with it (on top of `tags` when both
 * are given). `clear_category` / `clear_budget` / `clear_bill` unlink the relation; they cannot be
 * combined with an id or name for the same relation.
 */
trait EditsClassification
{
    /**
     * Schema entries for the merge and clear arguments.
     *
     * @return array<string, mixed>
     */
    protected function classificationEditSchema(JsonSchema $schema): array
    {
        return [
            'add_tags'       => $schema->array()->items($schema->string())->description('Tags to add, keeping the others.'),
            'remove_tags'    => $schema->array()->items($schema->string())->description('Tags to remove (case-insensitive).'),
            'clear_category' => $schema->boolean()->description('true = remove the category.'),
            'clear_budget'   => $schema->boolean()->description('true = remove the budget.'),
            'clear_bill'     => $schema->boolean()->description('true = unlink the bill.')
        ];
    }

    /**
     * Line entries that unlink the relations whose `clear_*` flag is set; rejects a clear combined with a value.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, null>
     */
    protected function clearedRelations(array $input): array
    {
        $line = [];
        foreach (['category', 'budget', 'bill'] as $relation) {
            if (true !== (bool) ($input[sprintf('clear_%s', $relation)] ?? false)) {
                continue;
            }
            foreach ([sprintf('%s_id', $relation), sprintf('%s_name', $relation)] as $field) {
                if (array_key_exists($field, $input) && null !== $input[$field] && '' !== $input[$field]) {
                    InvalidArgument::fail(sprintf('clear_%s', $relation), sprintf('clear_%s cannot be combined with %s.', $relation, $field));
                }
            }
            $line[sprintf('%s_id', $relation)]   = null;
            $line[sprintf('%s_name', $relation)] = null;
        }

        return $line;
    }

    /**
     * Current tag names of a journal.
     *
     * @return list<string>
     */
    protected function journalTags(TransactionJournal $journal): array
    {
        return array_values(array_map('strval', $journal->tags()->orderBy('tag')->pluck('tag')->all()));
    }

    /**
     * Apply `add` then `remove` to a tag set (case-insensitive, first spelling kept).
     *
     * @param list<string> $tags
     * @param list<string> $add
     * @param list<string> $remove
     *
     * @return list<string>
     */
    protected function mergeTags(array $tags, array $add, array $remove): array
    {
        $result = [];
        foreach ([...$tags, ...$add] as $tag) {
            $key = mb_strtolower($tag);
            if (!array_key_exists($key, $result)) {
                $result[$key] = $tag;
            }
        }
        foreach ($remove as $tag) {
            unset($result[mb_strtolower($tag)]);
        }

        return array_values($result);
    }
}
