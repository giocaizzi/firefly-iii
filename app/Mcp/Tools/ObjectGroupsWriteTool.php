<?php

/*
 * ObjectGroupsWriteTool.php
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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Update (title, order) or delete an object group. Groups are created by setting object_group_title on a piggy bank or subscription.')]
#[IsDestructive]
final class ObjectGroupsWriteTool extends AbstractApiTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['update', 'delete'])->required(),
            'id'     => $schema->integer()->required(),
            ...$this->writeSchema($schema, 'title, order.')
        ];
    }

    protected function run(Request $request): Response
    {
        $action = $this->actionArg($request, ['update', 'delete']);

        return $this->writeEntity($request, $action, 'object-groups', 'object group', ObjectGroupsReadTool::COMPACT, [
            'piggy banks'   => 'piggy-banks',
            'subscriptions' => 'bills'
        ]);
    }
}
