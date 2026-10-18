<?php

/*
 * SearchHelpResourceTest.php
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

namespace Tests\integration\Api\Mcp\Resources;

use FireflyIII\Mcp\Resources\SearchHelpResource;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\InvokesMcpServer;
use Tests\integration\TestCase;

/**
 * Covers SearchHelpResource: the syntax reference lists the operators the parser accepts.
 *
 * @internal
 *
 * @coversNothing
 */
final class SearchHelpResourceTest extends TestCase
{
    use EnsuresPassportKeys;
    use InvokesMcpServer;

    /**
     * @covers \FireflyIII\Mcp\Resources\SearchHelpResource
     */
    public function testGivenAuthenticatedUserWhenReadingSearchHelpThenListsOperators(): void
    {
        $response = $this->invokeResource($this->createAuthenticatedUser(), SearchHelpResource::class);
        $response->assertOk();

        $text = $this->responseText($response);
        self::assertStringContainsString('# Firefly III transaction search', $text);
        self::assertStringContainsString('amount_more', $text);
        self::assertStringContainsString('has_no_category', $text);
        self::assertStringContainsString('meta.warnings', $text);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
    }
}
