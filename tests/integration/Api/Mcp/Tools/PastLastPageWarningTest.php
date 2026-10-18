<?php

/*
 * PastLastPageWarningTest.php
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

namespace Tests\integration\Api\Mcp\Tools;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Mcp\Tools\BillsReadTool;
use FireflyIII\Mcp\Tools\ListAccountsTool;
use FireflyIII\Mcp\Tools\SearchTransactionsTool;
use Tests\integration\Api\Mcp\Concerns\CallsApiTools;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * Paged tools add `meta.warnings` when `page` is past the last page, for direct, API-backed
 * and search tools alike (AbstractMcpTool::withPagingHints).
 *
 * @internal
 *
 * @coversNothing
 */
final class PastLastPageWarningTest extends TestCase
{
    use CallsApiTools;
    use EnsuresPassportKeys;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\AbstractMcpTool
     */
    public function testPageInRangeHasNoWarning(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');

        $payload = $this->okJson($user, ListAccountsTool::class, ['page' => 1]);
        self::assertArrayNotHasKey('warnings', $payload['meta']);
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\AbstractMcpTool
     */
    public function testPagePastTheLastPageWarns(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $this->createAccount($user, AccountTypeEnum::ASSET, 'Savings');

        $accounts = $this->okJson($user, ListAccountsTool::class, ['page' => 3, 'limit' => 1]);
        self::assertSame(['page 3 is past the last page (2)'], $accounts['meta']['warnings']);
        self::assertFalse($accounts['meta']['has_more']);

        $bills = $this->okJson($user, BillsReadTool::class, ['action' => 'list', 'page' => 2]);
        self::assertSame(['page 2 is past the last page (1)'], $bills['meta']['warnings']);
    }

    /**
     * An empty result counts as one page; search keeps its own warnings next to this one.
     *
     * @covers \FireflyIII\Mcp\Tools\SearchTransactionsTool
     */
    public function testEmptyResultPastFirstPageWarns(): void
    {
        $user    = $this->createAuthenticatedUser();

        $payload = $this->okJson($user, SearchTransactionsTool::class, ['query' => 'nothing bogus_operator:x', 'page' => 2]);
        $warnings = $payload['meta']['warnings'];
        self::assertContains('page 2 is past the last page (1)', $warnings);
        self::assertGreaterThan(1, count($warnings), 'The search operator warning was dropped.');
    }
}
