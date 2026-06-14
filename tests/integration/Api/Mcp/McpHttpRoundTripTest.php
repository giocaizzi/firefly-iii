<?php

/*
 * McpHttpRoundTripTest.php
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

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Tests\integration\Api\Mcp\Concerns\CallsMcpEndpoint;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * End-to-end proof that the MCP surface is operational over its real HTTP transport.
 *
 * The per-tool suites drive the in-process harness, which bypasses routing, the
 * `auth:api` + feature-flag middleware and JSON-RPC dispatch. These tests close that gap:
 * a read, a get and a mutating write each travel the full stack, and a registry-driven
 * sweep proves every advertised tool is reachable and dispatchable — not just registered.
 *
 * @internal
 *
 * @coversNothing
 */
final class McpHttpRoundTripTest extends TestCase
{
    use CallsMcpEndpoint;
    use EnsuresPassportKeys;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testListToolRoundTripReturnsSeededDataOverHttp(): void
    {
        $user = $this->authenticate();
        $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');

        $body = $this->callTool('list-accounts-tool');

        self::assertFalse($body['result']['isError'], 'list-accounts-tool reported an error over HTTP.');
        $payload = $this->decodeToolPayload($body);
        self::assertNotEmpty($payload['data'], 'Seeded account did not surface through the HTTP transport.');
    }

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testGetToolRoundTripResolvesSeededEntityOverHttp(): void
    {
        $user    = $this->authenticate();
        $account = $this->createAccount($user, AccountTypeEnum::ASSET, 'Savings');

        $body = $this->callTool('get-account-tool', ['id' => $account->id]);

        self::assertFalse($body['result']['isError'], 'get-account-tool reported an error over HTTP.');
        $payload = $this->decodeToolPayload($body);
        self::assertSame((string) $account->id, (string) $payload['data']['id']);
    }

    /**
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testWriteToolRoundTripPersistsThroughHttpStack(): void
    {
        $user        = $this->authenticate();
        $source      = $this->createAccount($user, AccountTypeEnum::ASSET, 'Checking');
        $destination = $this->createAccount($user, AccountTypeEnum::EXPENSE, 'Groceries');
        $before      = TransactionJournal::count();

        $body = $this->callTool('create-withdrawal-tool', [
            'source_id'        => $source->id,
            'destination_id'   => $destination->id,
            'destination_name' => $destination->name,
            'amount'           => '12.50',
            'date'             => '2026-05-17',
            'description'      => 'Lunch',
        ]);

        self::assertFalse($body['result']['isError'], 'create-withdrawal-tool reported an error over HTTP.');
        self::assertSame($before + 1, TransactionJournal::count(), 'Write tool did not persist through the HTTP stack.');
    }

    /**
     * Every tool the server advertises must be dispatchable over HTTP. Empty arguments are
     * fine: a tool that validates returns a normal envelope, one that does not returns a
     * structured `isError` envelope — both prove the request reached the tool. A dropped or
     * crashing tool instead yields a top-level JSON-RPC `error`, which fails this sweep.
     *
     * @covers \FireflyIII\Mcp\Servers\FireflyServer
     */
    public function testEveryAdvertisedToolIsDispatchableOverHttp(): void
    {
        $this->authenticate();

        $names = array_column($this->mcpCall('tools/list')->assertOk()->json()['result']['tools'] ?? [], 'name');
        self::assertNotEmpty($names, 'tools/list advertised no tools to sweep.');

        foreach ($names as $name) {
            $body = $this->mcpCall('tools/call', ['name' => $name, 'arguments' => new \stdClass()])->assertOk()->json();

            self::assertArrayNotHasKey('error', $body, sprintf('Tool %s is not dispatchable over HTTP.', $name));
            self::assertArrayHasKey('content', $body['result'] ?? [], sprintf('Tool %s returned no content envelope.', $name));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
        $this->enableMcp();
    }

    private function authenticate(): User
    {
        $user = $this->createAuthenticatedUser();
        $this->actingAs($user, 'api');

        return $user;
    }
}
