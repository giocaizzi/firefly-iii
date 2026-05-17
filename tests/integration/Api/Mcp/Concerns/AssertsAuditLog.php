<?php

/*
 * AssertsAuditLog.php
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

namespace Tests\integration\Api\Mcp\Concerns;

use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monologger;
use PHPUnit\Framework\Assert;

/**
 * Captures `Log::channel('audit')` records into an in-memory Monolog TestHandler so each
 * test can assert exactly which audit lines its MCP write tool emits without writing to
 * disk or to the default stack-of-channels configuration.
 */
trait AssertsAuditLog
{
    private TestHandler $auditHandler;

    protected function assertAuditError(callable $predicate, string $failureMessage = 'Expected audit error record was not captured.'): void
    {
        foreach ($this->auditHandler->getRecords() as $record) {
            $level   = $record['level'] ?? null;
            $message = (string) ($record['message'] ?? '');
            /** @var array<string, mixed> $context */
            $context = (array) ($record['context'] ?? []);
            if (Monologger::ERROR === $level && $predicate($message, $context)) {
                Assert::assertTrue(true);

                return;
            }
        }

        Assert::fail($failureMessage);
    }

    /**
     * Assert that the audit channel received an `info` record whose message AND context
     * satisfy the provided predicate. Predicate receives `(message, context)` and returns
     * true on match.
     *
     * @param callable(string, array<string, mixed>): bool $predicate
     */
    protected function assertAuditInfo(callable $predicate, string $failureMessage = 'Expected audit info record was not captured.'): void
    {
        foreach ($this->auditHandler->getRecords() as $record) {
            // Monolog records expose `level`, `message` and `context` via array access.
            $level   = $record['level'] ?? null;
            $message = (string) ($record['message'] ?? '');
            /** @var array<string, mixed> $context */
            $context = (array) ($record['context'] ?? []);
            if (Monologger::INFO === $level && $predicate($message, $context)) {
                Assert::assertTrue(true);

                return;
            }
        }

        Assert::fail($failureMessage);
    }

    protected function captureAuditChannel(): void
    {
        $this->auditHandler = new TestHandler();
        $monolog            = new Monologger('audit', [$this->auditHandler]);
        $channel            = new Logger($monolog);

        /** @var LogManager $manager */
        $manager = Log::getFacadeRoot();
        // Direct injection bypasses the LogManager driver resolution so the channel always
        // hands back our TestHandler-backed Logger for the duration of this test.
        \Closure::bind(
            function () use ($channel): void {
                $this->channels['audit'] = $channel;
            },
            $manager,
            LogManager::class
        )();
    }
}
