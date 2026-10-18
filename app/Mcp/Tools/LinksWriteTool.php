<?php

/*
 * LinksWriteTool.php
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

#[Description('Create, update or delete a transaction link, or a link type (admin).')]
#[IsDestructive]
final class LinksWriteTool extends AbstractApiTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['create', 'update', 'delete'])->required(),
            'kind'   => $schema->string()->enum(['link', 'type']),
            'id'     => $schema->integer(),
            ...$this->writeSchema($schema, 'link: link_type_id or link_type_name, inward_id, outward_id (journal ids), notes; type: name, inward, outward.')
        ];
    }

    protected function run(Request $request): Response
    {
        $action = $this->actionArg($request, ['create', 'update', 'delete']);
        if ('type' === $this->enumArg($request, 'kind', ['link', 'type'], 'link')) {
            return $this->writeEntity($request, $action, 'link-types', 'link type', LinksReadTool::COMPACT['type'], ['links' => 'transactions']);
        }

        $data = null;
        if ('update' === $action) {
            // the API's link update resets omitted journal / type ids to 0, so send the current ones along
            $current = $this->api('GET', sprintf('transaction-links/%s', $this->idArg($request)))['data']['attributes'] ?? [];
            $data    = [...array_intersect_key($current, array_flip(['link_type_id', 'inward_id', 'outward_id'])), ...$this->dataArg($request)];
        }

        return $this->writeEntity($request, $action, 'transaction-links', 'transaction link', LinksReadTool::COMPACT['link'], data: $data);
    }
}
