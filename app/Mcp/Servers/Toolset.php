<?php

/*
 * Toolset.php
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

namespace FireflyIII\Mcp\Servers;

use FireflyIII\Mcp\Tools\AccountBalanceTool;
use FireflyIII\Mcp\Tools\BillsReadTool;
use FireflyIII\Mcp\Tools\BulkCreateTransactionsTool;
use FireflyIII\Mcp\Tools\CategoriesWriteTool;
use FireflyIII\Mcp\Tools\ChartDataTool;
use FireflyIII\Mcp\Tools\ClassifyTransactionsTool;
use FireflyIII\Mcp\Tools\ConvertTransactionTool;
use FireflyIII\Mcp\Tools\CreateDepositTool;
use FireflyIII\Mcp\Tools\CreateRuleTool;
use FireflyIII\Mcp\Tools\CreateTransferTool;
use FireflyIII\Mcp\Tools\CreateWithdrawalTool;
use FireflyIII\Mcp\Tools\DeleteTransactionTool;
use FireflyIII\Mcp\Tools\ExportTransactionsTool;
use FireflyIII\Mcp\Tools\GetAccountTool;
use FireflyIII\Mcp\Tools\GetAuditLogTool;
use FireflyIII\Mcp\Tools\GetBudgetTool;
use FireflyIII\Mcp\Tools\GetCategoryTool;
use FireflyIII\Mcp\Tools\GetRuleTool;
use FireflyIII\Mcp\Tools\GetTagTool;
use FireflyIII\Mcp\Tools\GetTransactionTool;
use FireflyIII\Mcp\Tools\HealthTool;
use FireflyIII\Mcp\Tools\InsightExpenseTool;
use FireflyIII\Mcp\Tools\InsightIncomeTool;
use FireflyIII\Mcp\Tools\ListAccountsTool;
use FireflyIII\Mcp\Tools\ListBillsTool;
use FireflyIII\Mcp\Tools\ListBudgetsTool;
use FireflyIII\Mcp\Tools\ListCategoriesTool;
use FireflyIII\Mcp\Tools\ListRuleGroupsTool;
use FireflyIII\Mcp\Tools\ListRulesTool;
use FireflyIII\Mcp\Tools\ListTagsTool;
use FireflyIII\Mcp\Tools\ListTransactionsTool;
use FireflyIII\Mcp\Tools\MatchTransactionsTool;
use FireflyIII\Mcp\Tools\SearchTransactionsTool;
use FireflyIII\Mcp\Tools\SummaryBasicTool;
use FireflyIII\Mcp\Tools\TagsWriteTool;
use FireflyIII\Mcp\Tools\TestRuleTool;
use FireflyIII\Mcp\Tools\UpdateRuleTool;
use FireflyIII\Mcp\Tools\UpdateTransactionTool;

/**
 * The tool set one MCP connection sees, picked with the `toolset` query parameter on the
 * endpoint (`/api/v1/mcp?toolset=admin`). Clients send every tool schema on every turn, so the
 * default `everyday` set keeps day-to-day bookkeeping lean and `admin` serves only the rest.
 * The two sets are disjoint: a client connected to both sees each tool once.
 */
enum Toolset: string
{
    case Admin    = 'admin';
    case Everyday = 'everyday';

    public const string QUERY_PARAMETER = 'toolset';

    /**
     * The only membership list: every tool not named here is in the admin toolset.
     *
     * @var list<class-string>
     */
    public const array EVERYDAY = [
        // core entity list/get
        ListAccountsTool::class,
        GetAccountTool::class,
        ListTransactionsTool::class,
        GetTransactionTool::class,
        ListBudgetsTool::class,
        GetBudgetTool::class,
        ListCategoriesTool::class,
        GetCategoryTool::class,
        ListTagsTool::class,
        GetTagTool::class,
        // search and bills
        SearchTransactionsTool::class,
        MatchTransactionsTool::class,
        ListBillsTool::class,
        BillsReadTool::class,
        // rules
        ListRulesTool::class,
        GetRuleTool::class,
        ListRuleGroupsTool::class,
        TestRuleTool::class,
        CreateRuleTool::class,
        UpdateRuleTool::class,
        // insight
        GetAuditLogTool::class,
        HealthTool::class,
        SummaryBasicTool::class,
        InsightExpenseTool::class,
        InsightIncomeTool::class,
        ChartDataTool::class,
        AccountBalanceTool::class,
        // transaction writes
        CreateWithdrawalTool::class,
        CreateDepositTool::class,
        CreateTransferTool::class,
        UpdateTransactionTool::class,
        DeleteTransactionTool::class,
        ConvertTransactionTool::class,
        BulkCreateTransactionsTool::class,
        ClassifyTransactionsTool::class,
        // classification and export
        TagsWriteTool::class,
        CategoriesWriteTool::class,
        ExportTransactionsTool::class,
    ];

    public function includes(string $toolClass): bool
    {
        return (self::Everyday === $this) === in_array($toolClass, self::EVERYDAY, true);
    }
}
