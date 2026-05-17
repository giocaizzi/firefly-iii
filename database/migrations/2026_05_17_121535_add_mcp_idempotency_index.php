<?php

/*
 * 2026_05_17_121535_add_mcp_idempotency_index.php
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

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index `journal_meta(name, data)` for MCP idempotency-key lookups (WIP_MCP D-027).
 *
 * `data` is TEXT in MySQL, so the composite is created via raw SQL with a 191-byte
 * prefix on `data`. On SQLite (used in tests) and Postgres the standard schema builder
 * succeeds without a prefix. Any error is swallowed in the same defensive style as
 * 2024_03_03_174645_add_indices.php — the index is an optimization, not a contract.
 */
return new class extends Migration {
    private const string INDEX_NAME = 'journal_meta_mcp_idempotency_index';

    public function down(): void
    {
        try {
            Schema::table('journal_meta', static function (Blueprint $table): void {
                $table->dropIndex(self::INDEX_NAME);
            });
        } catch (QueryException $e) {
            app('log')->warning(sprintf('Could not drop %s on journal_meta: %s', self::INDEX_NAME, $e->getMessage()));
        }
    }

    public function up(): void
    {
        try {
            $driver = DB::connection()->getDriverName();

            if ('mysql' === $driver || 'mariadb' === $driver) {
                DB::statement(sprintf('CREATE INDEX `%s` ON `journal_meta` (`name`, `data`(191))', self::INDEX_NAME));

                return;
            }

            Schema::table('journal_meta', static function (Blueprint $table): void {
                $table->index(['name', 'data'], self::INDEX_NAME);
            });
        } catch (QueryException $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'Duplicate key name') || str_contains($message, 'already exists')) {
                return;
            }
            app('log')->warning(sprintf('Could not create %s on journal_meta: %s', self::INDEX_NAME, $message));
        }
    }
};
