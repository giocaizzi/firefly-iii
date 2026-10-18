<?php

/*
 * ExportTransactionsTool.php
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

use FireflyIII\Mcp\Support\InvalidArgument;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use SplFileObject;
use SplTempFileObject;

#[Description('Export transactions as CSV (Firefly\'s own export), optionally only some columns; output is capped and says when it was cut.')]
#[IsReadOnly]
#[IsIdempotent]
final class ExportTransactionsTool extends AbstractApiTool
{
    public const int DEFAULT_MAX_BYTES = 100_000;

    public const int MAX_BYTES = 1_000_000;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'start'     => $schema->string()->description('YYYY-MM-DD, default a year ago.'),
            'end'       => $schema->string()->description('YYYY-MM-DD, default today.'),
            'accounts'  => $schema->array()->items($schema->integer())->description('Asset account ids, default all.'),
            'columns'   => $schema->array()->items($schema->string())->description('CSV columns to keep, in this order; default all.'),
            'max_bytes' => $schema->integer()->description(sprintf('Output cap, default %d, max %d.', self::DEFAULT_MAX_BYTES, self::MAX_BYTES))
        ];
    }

    protected function run(Request $request): Response
    {
        $maxBytes = $this->positiveIntArg($request, 'max_bytes', self::DEFAULT_MAX_BYTES);
        if ($maxBytes > self::MAX_BYTES) {
            InvalidArgument::fail('max_bytes', sprintf('max_bytes must be at most %d.', self::MAX_BYTES), ['max' => self::MAX_BYTES]);
        }
        $query = [
            'type'     => 'csv',
            'start'    => $this->dateArg($request, 'start')?->format('Y-m-d'),
            'end'      => $this->dateArg($request, 'end')?->format('Y-m-d'),
            'accounts' => $this->accountsArg($request)
        ];

        $rows   = $this->parse($this->apiContent('GET', 'data/export/transactions', $query));
        $header = array_shift($rows) ?? [];
        $keep   = $this->columnIndexes($request, $header);

        $pick  = static fn(array $row): array => array_map(static fn(int $index): null|string => $row[$index] ?? null, $keep);
        $csv   = $this->line($pick($header));
        $shown = 0;
        foreach ($rows as $row) {
            $line = $this->line($pick($row));
            if ((strlen($csv) + strlen($line)) > $maxBytes) {
                break;
            }
            $csv .= $line;
            ++$shown;
        }
        if ($shown < count($rows)) {
            $csv .= sprintf("# truncated: %d of %d rows shown (max_bytes %d); narrow the dates, accounts or columns.\n", $shown, count($rows), $maxBytes);
        }

        return Response::text($csv);
    }

    private function accountsArg(Request $request): null|string
    {
        $raw = $request->get('accounts');
        if (null === $raw) {
            return null;
        }
        if (!is_array($raw) || [] === $raw || [] !== array_filter($raw, static fn(mixed $id): bool => !is_int($id) || $id < 1)) {
            InvalidArgument::fail('accounts', 'accounts must be a non-empty list of account ids.', ['received' => $raw]);
        }

        return implode(',', $raw);
    }

    /**
     * Positions of the requested columns, in the requested order (default: all).
     *
     * @param list<null|string> $header
     *
     * @return list<int>
     */
    private function columnIndexes(Request $request, array $header): array
    {
        $raw = $request->get('columns');
        if (null === $raw) {
            return array_keys($header);
        }
        $positions = array_flip(array_map('strval', $header));
        $keep      = [];
        foreach (is_array($raw) ? $raw : [$raw] as $column) {
            if (!is_string($column) || !array_key_exists($column, $positions)) {
                InvalidArgument::fail('columns', sprintf('Unknown column "%s".', is_scalar($column) ? (string) $column : gettype($column)), [
                    'allowed' => $header
                ]);
            }
            $keep[] = $positions[$column];
        }

        return array_values(array_unique($keep));
    }

    /**
     * @param list<null|string> $fields
     */
    private function line(array $fields): string
    {
        $file   = new SplTempFileObject();
        $length = (int) $file->fputcsv($fields, ',', '"', '');
        $file->rewind();

        return (string) $file->fread(max(1, $length));
    }

    /**
     * @return list<list<null|string>>
     */
    private function parse(string $csv): array
    {
        $file = new SplTempFileObject();
        $file->fwrite($csv);
        $file->rewind();
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);
        $file->setCsvControl(',', '"', '');
        $rows = [];
        foreach ($file as $row) {
            if (is_array($row) && [null] !== $row) {
                $rows[] = array_values($row);
            }
        }

        return $rows;
    }
}
