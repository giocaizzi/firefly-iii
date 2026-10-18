<?php

/*
 * SearchHelpResource.php
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

namespace FireflyIII\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

/**
 * Search syntax reference for search-transactions, read on demand instead of riding along in
 * every tools/list. The operator list is generated from config('search.operators'), so it
 * always matches what the query parser accepts on this instance.
 */
#[Uri(SearchHelpResource::URI)]
#[MimeType('text/markdown')]
#[Description('Search syntax, examples and the full operator list for search-transactions.')]
final class SearchHelpResource extends Resource
{
    public const string URI = 'firefly-iii://help/search';

    public function handle(Request $request): Response
    {
        /** @var array<string, array{alias: bool, alias_for?: string, needs_context: bool}> $operators */
        $operators = (array) config('search.operators');

        $withValue = [];
        $flags     = [];
        $aliases   = [];
        foreach ($operators as $name => $operator) {
            if (true === $operator['alias']) {
                $aliases[] = sprintf('%s=%s', $name, $operator['alias_for'] ?? '?');

                continue;
            }
            if (true === $operator['needs_context']) {
                $withValue[] = $name;

                continue;
            }
            $flags[] = $name;
        }

        $text = <<<'MARKDOWN'
            # Firefly III transaction search

            A query is free text plus `operator:value` pairs, all combined with AND.

            - Free text matches the description: `coffee`. Quote phrases: `"coffee shop"`.
            - Values with spaces need quotes: `category_is:"Eating out"`.
            - Negate an operator or word with a leading `-`: `-category_is:Food`, `-refund`.
            - Parentheses group terms, mainly to negate a group: `-(category_is:Food amount_less:5)`. There is no OR.
            - Dates are YYYY-MM-DD: `date_after:2026-01-01 date_before:2026-01-31`, or `date_on:2026-01-15`.
            - Amounts are plain decimals: `amount_more:50`, `amount_less:10.5`, `amount_is:12`.
            - Most text operators come as `_is`, `_contains`, `_starts`, `_ends` variants.
            - Flag operators take no value (or `:true`): `has_no_category`, `has_attachments`.
            - Unknown or malformed operators are ignored by the search; the tool lists them in `meta.warnings`.

            Examples:

            - `amount_more:100 date_after:2026-01-01 type:withdrawal`
            - `source_account_is:"Checking" -has_any_tag`
            - `description_contains:invoice has_no_category`
            - `notes_contains:refund currency_is:USD`

            MARKDOWN;

        $text .= "\n## Operators that take a value\n\n" . implode(', ', $withValue) . "\n";
        $text .= "\n## Flag operators (no value)\n\n" . implode(', ', $flags) . "\n";
        $text .= "\n## Aliases\n\n" . implode(', ', $aliases) . "\n";

        return Response::text($text);
    }
}
