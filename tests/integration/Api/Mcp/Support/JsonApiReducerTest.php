<?php

/*
 * JsonApiReducerTest.php
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

namespace Tests\integration\Api\Mcp\Support;

use FireflyIII\Mcp\Support\JsonApiReducer;
use Tests\integration\TestCase;

/**
 * Class JsonApiReducerTest
 *
 * Structural rules covered: collection/single-data, null handling, relationships
 * (single + collection), pagination preservation, recursive `included` reduction.
 *
 * @internal
 *
 * @coversNothing
 */
final class JsonApiReducerTest extends TestCase
{
    protected $seed = false;

    /**
     * @covers \FireflyIII\Mcp\Support\JsonApiReducer
     */
    public function testGivenCollectionWhenReducingThenFlattensEveryItem(): void
    {
        $document = [
            'data' => [
                [
                    'type'       => 'accounts',
                    'id'         => '1',
                    'attributes' => ['name' => 'Checking', 'active' => true],
                    'links'      => ['self' => 'https://example.test/api/v1/accounts/1']
                ],
                [
                    'type'       => 'accounts',
                    'id'         => '2',
                    'attributes' => ['name' => 'Savings', 'active' => false]
                ]
            ]
        ];

        $reduced = new JsonApiReducer()->reduce($document);

        self::assertSame(
            [
                'data' => [
                    ['id' => 1, 'name' => 'Checking', 'active' => true],
                    ['id' => 2, 'name' => 'Savings', 'active' => false]
                ]
            ],
            $reduced
        );
    }

    /**
     * @covers \FireflyIII\Mcp\Support\JsonApiReducer
     */
    public function testGivenIncludedWhenReducingThenRecursesIntoIncludedKeyedByTypeId(): void
    {
        $document = [
            'data'     => [
                'type'          => 'transactions',
                'id'            => '7',
                'attributes'    => ['description' => 'Lunch'],
                'relationships' => [
                    'category' => ['data' => ['type' => 'categories', 'id' => '12']]
                ]
            ],
            'included' => [
                [
                    'type'       => 'categories',
                    'id'         => '12',
                    'attributes' => ['name' => 'Food', 'notes' => null]
                ]
            ]
        ];

        $reduced = new JsonApiReducer()->reduce($document);

        self::assertArrayHasKey('_included', $reduced);
        self::assertSame(['categories/12' => ['id' => 12, 'name' => 'Food']], $reduced['_included']);
    }

    /**
     * @covers \FireflyIII\Mcp\Support\JsonApiReducer
     */
    public function testGivenNullAttributesWhenIncludeNullsFalseThenAttributesAreDropped(): void
    {
        $document = [
            'data' => [
                'type'       => 'accounts',
                'id'         => '1',
                'attributes' => ['name' => 'Checking', 'notes' => null, 'iban' => null, 'active' => true]
            ]
        ];

        $reduced = new JsonApiReducer()->reduce($document);

        self::assertSame(['data' => ['id' => 1, 'name' => 'Checking', 'active' => true]], $reduced);
    }

    /**
     * @covers \FireflyIII\Mcp\Support\JsonApiReducer
     */
    public function testGivenNullAttributesWhenIncludeNullsTrueThenAttributesArePreserved(): void
    {
        $document = [
            'data' => [
                'type'       => 'accounts',
                'id'         => '1',
                'attributes' => ['name' => 'Checking', 'notes' => null]
            ]
        ];

        $reduced = new JsonApiReducer()->reduce($document, ['include_nulls' => true]);

        self::assertSame(['data' => ['id' => 1, 'name' => 'Checking', 'notes' => null]], $reduced);
    }

    /**
     * @covers \FireflyIII\Mcp\Support\JsonApiReducer
     */
    public function testGivenPaginationMetaWhenReducingThenMetaIsPreservedVerbatim(): void
    {
        $document = [
            'data' => [],
            'meta' => [
                'pagination' => [
                    'total'        => 100,
                    'count'        => 25,
                    'per_page'     => 25,
                    'current_page' => 1,
                    'total_pages'  => 4,
                    'links'        => ['next' => 'https://example.test/api/v1/accounts?page=2']
                ],
                'extra'      => 'preserved'
            ]
        ];

        $reduced = new JsonApiReducer()->reduce($document);

        self::assertSame($document['meta'], $reduced['meta']);
        self::assertSame([], $reduced['data']);
    }

    /**
     * @covers \FireflyIII\Mcp\Support\JsonApiReducer
     */
    public function testGivenRelationshipsWhenReducingThenFlattensToIdScalarsAndArrays(): void
    {
        $document = [
            'data' => [
                'type'          => 'transactions',
                'id'            => '7',
                'attributes'    => ['description' => 'Lunch'],
                'relationships' => [
                    'category'    => ['data' => ['type' => 'categories', 'id' => '12']],
                    'tags'        => [
                        'data' => [
                            ['type' => 'tags', 'id' => '3'],
                            ['type' => 'tags', 'id' => '4']
                        ]
                    ],
                    'attachments' => ['data' => []]
                ]
            ]
        ];

        $reduced = new JsonApiReducer()->reduce($document);

        self::assertSame(
            [
                'data' => [
                    'id'              => 7,
                    'description'     => 'Lunch',
                    'category_id'     => 12,
                    'tags_ids'        => [3, 4],
                    'attachments_ids' => []
                ]
            ],
            $reduced
        );
    }

    /**
     * @covers \FireflyIII\Mcp\Support\JsonApiReducer
     */
    public function testGivenSingleItemWhenReducingThenReturnsFlatObject(): void
    {
        $document = [
            'data' => [
                'type'       => 'budgets',
                'id'         => '42',
                'attributes' => ['name' => 'Groceries', 'active' => true]
            ]
        ];

        $reduced = new JsonApiReducer()->reduce($document);

        self::assertSame(['data' => ['id' => 42, 'name' => 'Groceries', 'active' => true]], $reduced);
    }
}
