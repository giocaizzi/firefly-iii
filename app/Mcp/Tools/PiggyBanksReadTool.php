<?php

/*
 * PiggyBanksReadTool.php
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

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Piggy banks: list, get, or the money events (added/removed) of one.')]
#[IsReadOnly]
#[IsIdempotent]
final class PiggyBanksReadTool extends AbstractApiTool
{
    public const array COMPACT = [
        'id',
        'name',
        'target_amount',
        'current_amount',
        'left_to_save',
        'percentage',
        'currency_code',
        'start_date',
        'target_date',
        'active',
        'object_group_title',
        'accounts'
    ];

    private const array EVENT_COMPACT = ['id', 'created_at', 'amount', 'currency_code', 'transaction_group_id'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'get', 'events'])->required(),
            'id'     => $schema->integer()->description('For get/events.'),
            ...$this->readSchema($schema)
        ];
    }

    protected function run(Request $request): Response
    {
        $action = $this->actionArg($request, ['list', 'get', 'events']);
        $query  = $this->listQuery($request, []);

        return match ($action) {
            'list'  => $this->respondApi($this->api('GET', 'piggy-banks', $query), $request, self::COMPACT),
            'get'   => $this->respondApi($this->api('GET', sprintf('piggy-banks/%s', $this->idArg($request))), $request, self::COMPACT),
            default => $this->respondApi($this->api('GET', sprintf('piggy-banks/%s/events', $this->idArg($request)), $query), $request, self::EVENT_COMPACT)
        };
    }
}
