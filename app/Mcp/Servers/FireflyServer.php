<?php

/*
 * FireflyServer.php
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

use FireflyIII\Mcp\Resources\AccountTypesCatalogResource;
use FireflyIII\Mcp\Resources\CurrenciesCatalogResource;
use FireflyIII\Mcp\Resources\LinkTypesCatalogResource;
use FireflyIII\Mcp\Resources\SearchHelpResource;
use FireflyIII\Mcp\Resources\TransactionTypesCatalogResource;
use FireflyIII\Mcp\Resources\UserAccountsQueryResource;
use FireflyIII\Mcp\Resources\UserAccountsResource;
use FireflyIII\Mcp\Resources\UserBudgetsResource;
use FireflyIII\Mcp\Resources\UserCategoriesResource;
use FireflyIII\Mcp\Resources\UserTagsResource;
use FireflyIII\Mcp\Tools\AccountBalanceTool;
use FireflyIII\Mcp\Tools\AccountsWriteTool;
use FireflyIII\Mcp\Tools\ApplyRuleTool;
use FireflyIII\Mcp\Tools\AttachmentsReadTool;
use FireflyIII\Mcp\Tools\AttachmentsWriteTool;
use FireflyIII\Mcp\Tools\BillsReadTool;
use FireflyIII\Mcp\Tools\BillsWriteTool;
use FireflyIII\Mcp\Tools\BudgetsReadTool;
use FireflyIII\Mcp\Tools\BudgetsWriteTool;
use FireflyIII\Mcp\Tools\BulkCreateTransactionsTool;
use FireflyIII\Mcp\Tools\CategoriesWriteTool;
use FireflyIII\Mcp\Tools\ChartDataTool;
use FireflyIII\Mcp\Tools\ClassifyTransactionsTool;
use FireflyIII\Mcp\Tools\ConvertTransactionTool;
use FireflyIII\Mcp\Tools\CreateDepositTool;
use FireflyIII\Mcp\Tools\CreateRuleGroupTool;
use FireflyIII\Mcp\Tools\CreateRuleTool;
use FireflyIII\Mcp\Tools\CreateTransferTool;
use FireflyIII\Mcp\Tools\CreateWithdrawalTool;
use FireflyIII\Mcp\Tools\CurrenciesReadTool;
use FireflyIII\Mcp\Tools\CurrenciesWriteTool;
use FireflyIII\Mcp\Tools\DeleteAccountTool;
use FireflyIII\Mcp\Tools\DeleteCategoryTool;
use FireflyIII\Mcp\Tools\DeleteRuleTool;
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
use FireflyIII\Mcp\Tools\LinksReadTool;
use FireflyIII\Mcp\Tools\LinksWriteTool;
use FireflyIII\Mcp\Tools\ListAccountsTool;
use FireflyIII\Mcp\Tools\ListBillsTool;
use FireflyIII\Mcp\Tools\ListBudgetsTool;
use FireflyIII\Mcp\Tools\ListCategoriesTool;
use FireflyIII\Mcp\Tools\ListPiggyBanksTool;
use FireflyIII\Mcp\Tools\ListRuleGroupsTool;
use FireflyIII\Mcp\Tools\ListRulesTool;
use FireflyIII\Mcp\Tools\ListTagsTool;
use FireflyIII\Mcp\Tools\ListTransactionsTool;
use FireflyIII\Mcp\Tools\ListWebhooksTool;
use FireflyIII\Mcp\Tools\ObjectGroupsReadTool;
use FireflyIII\Mcp\Tools\ObjectGroupsWriteTool;
use FireflyIII\Mcp\Tools\PiggyBanksReadTool;
use FireflyIII\Mcp\Tools\PiggyBanksWriteTool;
use FireflyIII\Mcp\Tools\RecurrencesReadTool;
use FireflyIII\Mcp\Tools\RecurrencesWriteTool;
use FireflyIII\Mcp\Tools\SearchTransactionsTool;
use FireflyIII\Mcp\Tools\SummaryBasicTool;
use FireflyIII\Mcp\Tools\TagsWriteTool;
use FireflyIII\Mcp\Tools\TestRuleTool;
use FireflyIII\Mcp\Tools\UpdateRuleGroupTool;
use FireflyIII\Mcp\Tools\UpdateRuleTool;
use FireflyIII\Mcp\Tools\UpdateTransactionTool;
use FireflyIII\Mcp\Tools\WebhooksWriteTool;
use Laravel\Mcp\Server;

/**
 * Single MCP server entrypoint for Firefly III.
 *
 * The feature-flag gate lives in FireflyIII\Http\Middleware\McpFeatureFlag, chained on
 * the route in routes/ai.php alongside auth:api. Keeping the gate in middleware (rather
 * than overriding boot() here) means the request is rejected before any MCP framework
 * code runs, mirroring the webhook precedent.
 */
final class FireflyServer extends Server
{
    protected string $name = 'Firefly III MCP';

    protected string $version;

    /**
     * Override laravel/mcp's default page size (15) so all MCP tools land in a single
     * tools/list response. Many MCP clients — Claude Code's SDK among them — do NOT
     * automatically follow nextCursor, which silently hides the trailing tools (aggregates
     * + writes) from the agent. Keeping the entire surface visible after one round-trip
     * costs a few kB of additional response payload. maxPaginationLength (laravel/mcp default 50)
     * is raised with it, or the surface past 50 tools would page again.
     */
    public int $defaultPaginationLength = 100;

    public int $maxPaginationLength = 100;

    protected array $tools = [
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
        // search
        SearchTransactionsTool::class,
        // power-user
        ListBillsTool::class,
        ListRulesTool::class,
        GetRuleTool::class,
        ListRuleGroupsTool::class,
        ListPiggyBanksTool::class,
        ListWebhooksTool::class,
        GetAuditLogTool::class,
        HealthTool::class,
        // aggregates
        SummaryBasicTool::class,
        InsightExpenseTool::class,
        InsightIncomeTool::class,
        ChartDataTool::class,
        AccountBalanceTool::class,
        // writes
        CreateWithdrawalTool::class,
        CreateDepositTool::class,
        CreateTransferTool::class,
        UpdateTransactionTool::class,
        DeleteTransactionTool::class,
        ConvertTransactionTool::class,
        BulkCreateTransactionsTool::class,
        ClassifyTransactionsTool::class,
        // rule management
        UpdateRuleTool::class,
        DeleteRuleTool::class,
        CreateRuleTool::class,
        CreateRuleGroupTool::class,
        UpdateRuleGroupTool::class,
        TestRuleTool::class,
        ApplyRuleTool::class,
        // destructive entity management
        DeleteAccountTool::class,
        DeleteCategoryTool::class,
        // full coverage: one read and one write tool per area, on the REST API
        BudgetsReadTool::class,
        BudgetsWriteTool::class,
        BillsReadTool::class,
        BillsWriteTool::class,
        PiggyBanksReadTool::class,
        PiggyBanksWriteTool::class,
        RecurrencesReadTool::class,
        RecurrencesWriteTool::class,
        CurrenciesReadTool::class,
        CurrenciesWriteTool::class,
        AttachmentsReadTool::class,
        AttachmentsWriteTool::class,
        LinksReadTool::class,
        LinksWriteTool::class,
        ObjectGroupsReadTool::class,
        ObjectGroupsWriteTool::class,
        TagsWriteTool::class,
        CategoriesWriteTool::class,
        AccountsWriteTool::class,
        WebhooksWriteTool::class,
        ExportTransactionsTool::class
    ];

    protected array $resources = [
        CurrenciesCatalogResource::class,
        AccountTypesCatalogResource::class,
        TransactionTypesCatalogResource::class,
        LinkTypesCatalogResource::class,
        UserAccountsResource::class,
        UserTagsResource::class,
        UserCategoriesResource::class,
        UserBudgetsResource::class,
        UserAccountsQueryResource::class,
        SearchHelpResource::class
    ];

    protected string $instructions = <<<'MARKDOWN'
        Firefly III personal-finance MCP server. Tools and resources operate on the
        authenticated user's data. Auth is MCP-spec OAuth (RFC 8414 / RFC 9728 / RFC 7591)
        backed by Laravel Passport — clients discover the auth server via the
        /.well-known/oauth-* endpoints, register via /oauth/register, and present
        a bearer access token carrying the `mcp:use` scope.

        Output: compact rows by default, nulls and empty values omitted. `compact=false`
        returns every attribute, `verbose=true` the raw JSON:API document. Lists page with
        `page` / `limit` (default 25, max 200) and report `meta.has_more` / `meta.next_page`.
        Transaction rows: `id` is the transaction (group) id every tool accepts; `journal_id`
        appears only when it differs. Split groups are {group_id, title, splits}. Amounts are in the primary
        currency (`meta.primary_currency`) unless the row has `currency`. Invalid arguments
        return {"error":"invalid_argument","field":..,"message":..}.
        Search syntax: resource firefly-iii://help/search.
        MARKDOWN;

    public function __construct(\Laravel\Mcp\Server\Contracts\Transport $transport)
    {
        parent::__construct($transport);
        $this->version = (string) config('firefly.version');
    }
}
