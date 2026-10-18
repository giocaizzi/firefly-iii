<?php

/*
 * ObjectGroupsReadTool.php
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

#[Description('Object groups (which group piggy banks and subscriptions): list, get, or the piggy banks / subscriptions in one.')]
#[IsReadOnly]
#[IsIdempotent]
final class ObjectGroupsReadTool extends AbstractApiTool
{
    public const array COMPACT = ['id', 'title', 'order'];

    private const array ACTIONS = ['list', 'get', 'piggy-banks', 'bills'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(self::ACTIONS)->required(),
            'id'     => $schema->integer(),
            ...$this->readSchema($schema)
        ];
    }

    protected function run(Request $request): Response
    {
        $action = $this->actionArg($request, self::ACTIONS);
        $query  = $this->listQuery($request, []);
        if ('list' === $action) {
            return $this->respondApi($this->api('GET', 'object-groups', $query), $request, self::COMPACT);
        }
        $path = sprintf('object-groups/%s', $this->idArg($request));

        return match ($action) {
            'get'         => $this->respondApi($this->api('GET', $path), $request, self::COMPACT),
            'piggy-banks' => $this->respondApi($this->api('GET', $path . '/piggy-banks', $query), $request, PiggyBanksReadTool::COMPACT),
            default       => $this->respondApi($this->api('GET', $path . '/bills', $query), $request, BillsReadTool::COMPACT)
        };
    }
}
