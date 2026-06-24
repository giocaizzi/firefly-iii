<?php

/*
 * ServerManifestTest.php
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

namespace Tests\integration\Api\Mcp;

use Tests\integration\Api\Mcp\Concerns\CallsMcpEndpoint;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\TestCase;

/**
 * Pins the discovery surface clients actually see over `tools/list` and `resources/list`.
 *
 * The per-tool tests invoke each tool class directly, so they stay green even if a tool
 * falls out of the FireflyServer registry or is hidden past a pagination cursor. This
 * suite is the contract: it fails the moment the advertised surface drifts — exactly the
 * regression an upstream rebase is most likely to introduce silently.
 *
 * @internal
 *
 * @coversNothing
 */
final class ServerManifestTest extends TestCase
{
    use CallsMcpEndpoint;
    use EnsuresPassportKeys;

    /**
     * The complete tool surface, by registered name. Changing this set is a deliberate,
     * reviewable act — adding or removing a tool must update this list.
     */
    private const array EXPECTED_TOOLS = [
        'bulk-create-transactions-tool',
        'chart-data-tool',
        'convert-transaction-tool',
        'create-deposit-tool',
        'create-transfer-tool',
        'create-withdrawal-tool',
        'delete-account-tool',
        'delete-category-tool',
        'delete-rule-tool',
        'delete-transaction-tool',
        'get-account-tool',
        'get-audit-log-tool',
        'get-budget-tool',
        'get-category-tool',
        'get-tag-tool',
        'get-transaction-tool',
        'insight-expense-tool',
        'insight-income-tool',
        'list-accounts-tool',
        'list-bills-tool',
        'list-budgets-tool',
        'list-categories-tool',
        'list-piggy-banks-tool',
        'list-rules-tool',
        'list-tags-tool',
        'list-transactions-tool',
        'list-webhooks-tool',
        'search-transactions-tool',
        'summary-basic-tool',
        'update-rule-tool',
        'update-transaction-tool',
    ];

    private const array EXPECTED_RESOURCES = [
        'account-types-catalog-resource',
        'currencies-catalog-resource',
        'link-types-catalog-resource',
        'transaction-types-catalog-resource',
        'user-accounts-resource',
        'user-budgets-resource',
        'user-categories-resource',
        'user-tags-resource',
    ];

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testToolsListExposesEntireSurfaceInOneResponse(): void
    {
        $body = $this->mcpCall('tools/list')->assertOk()->json();

        $names = array_column($body['result']['tools'] ?? [], 'name');
        sort($names);
        $expected = self::EXPECTED_TOOLS;
        sort($expected);

        self::assertSame($expected, $names, 'Advertised tool surface drifted from the expected set.');

        // The whole surface must arrive in a single page; many clients do not follow nextCursor.
        // This guards FireflyServer::$defaultPaginationLength against an upstream default reset.
        self::assertArrayNotHasKey('nextCursor', $body['result'], 'tools/list paginated; trailing tools would be invisible to clients.');

        foreach ($body['result']['tools'] as $tool) {
            self::assertNotEmpty($tool['description'] ?? '', sprintf('Tool %s has no description.', $tool['name']));
            self::assertArrayHasKey('inputSchema', $tool, sprintf('Tool %s exposes no input schema.', $tool['name']));
        }
    }

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testResourcesListExposesEntireResourceSurface(): void
    {
        $body = $this->mcpCall('resources/list')->assertOk()->json();

        $names = array_column($body['result']['resources'] ?? [], 'name');
        sort($names);
        $expected = self::EXPECTED_RESOURCES;
        sort($expected);

        self::assertSame($expected, $names, 'Advertised resource surface drifted from the expected set.');
        self::assertArrayNotHasKey('nextCursor', $body['result'], 'resources/list paginated; trailing resources would be invisible to clients.');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
        $this->enableMcp();
        $this->actingAs($this->createAuthenticatedUser(), 'api');
    }
}
