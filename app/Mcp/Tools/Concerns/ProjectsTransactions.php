<?php

/*
 * ProjectsTransactions.php
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
use FireflyIII\Mcp\Support\LastChanged;
use FireflyIII\Mcp\Support\TransactionProjector;
use FireflyIII\Support\Facades\Amount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

use function Safe\preg_match;

/**
 * Compact transaction output for every tool that returns transaction groups.
 *
 * Output modes: compact (default, TransactionProjector), `compact=false` (the generic reduced
 * shape: every transformer attribute), `verbose=true` (raw JSON:API). Shape arguments are
 * validated up front via validateShapeArgs() so write tools never fail after committing.
 */
trait ProjectsTransactions
{
    /**
     * Compact projection of one transaction-group JSON:API document (single item or collection).
     *
     * @param array<string, mixed> $jsonApiDocument
     *
     * @return array<string, mixed>
     */
    protected function projectTransactions(array $jsonApiDocument, Request $request): array
    {
        return new TransactionProjector()->project(
            $jsonApiDocument,
            Amount::getPrimaryCurrency()->code,
            $this->fieldsArg($request),
            $this->descriptionMaxArg($request),
            $this->includeNulls($request)
        );
    }

    /**
     * @param array<string, mixed> $jsonApiDocument
     *
     * @return array<string, mixed>
     */
    protected function shape(array $jsonApiDocument, Request $request): array
    {
        $jsonApiDocument = LastChanged::apply($jsonApiDocument);
        if (!$this->wantsCompact($request)) {
            return self::fieldsIgnoredWarning(parent::shape($jsonApiDocument, $request), $request);
        }

        return $this->projectTransactions($jsonApiDocument, $request);
    }

    /**
     * Schema entries for the transaction output arguments.
     *
     * @return array<string, mixed>
     */
    protected function transactionOutputSchema(JsonSchema $schema): array
    {
        return [
            'fields'          => $schema->array()->items($schema->string())->description('Row keys to keep: compact or raw split keys (e.g. source_id).'),
            'description_max' => $schema
                ->integer()
                ->description(sprintf('Description cap, default %d, 0 = none.', TransactionProjector::DEFAULT_DESCRIPTION_MAX)),
            'compact'         => $schema->boolean()->description('false = all attributes.'),
            ...$this->outputFlagsSchema($schema)
        ];
    }

    /**
     * Reject malformed output arguments before the tool does any work.
     */
    protected function validateShapeArgs(Request $request): void
    {
        $this->fieldsArg($request);
        $this->descriptionMaxArg($request);
    }

    protected function wantsCompact(Request $request): bool
    {
        return (bool) $request->get('compact', true);
    }

    /**
     * Output arguments for write tools (their echo is a single group, so no field selection).
     *
     * @return array<string, mixed>
     */
    protected function writeOutputSchema(JsonSchema $schema): array
    {
        return [
            'compact' => $schema->boolean()->description('false = all attributes.'),
            ...$this->outputFlagsSchema($schema)
        ];
    }

    private function descriptionMaxArg(Request $request): int
    {
        $raw = $request->get('description_max');
        if (null === $raw) {
            return TransactionProjector::DEFAULT_DESCRIPTION_MAX;
        }
        if (is_string($raw) && 1 === preg_match('/^-?\d+$/', trim($raw))) {
            $raw = (int) trim($raw);
        }
        if (!is_int($raw) || $raw < 0) {
            InvalidArgument::fail('description_max', 'description_max must be an integer >= 0 (0 = no cap).', ['received' => $raw]);
        }

        return $raw;
    }

    /**
     * @return null|list<string>
     */
    private function fieldsArg(Request $request): null|array
    {
        $raw = $request->get('fields');
        if (null === $raw) {
            return null;
        }
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            InvalidArgument::fail('fields', 'fields must be a list of field names.', ['received' => $raw]);
        }
        $fields = [];
        foreach ($raw as $field) {
            if (!is_string($field) || '' === trim($field)) {
                InvalidArgument::fail('fields', 'fields must be a list of non-empty field names.', ['received' => $raw]);
            }
            $fields[] = trim($field);
        }

        return [] === $fields ? null : array_values(array_unique($fields));
    }
}
