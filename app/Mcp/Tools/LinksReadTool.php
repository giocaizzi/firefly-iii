<?php

/*
 * LinksReadTool.php
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

#[Description('Transaction links (between journals) and link types: list or get.')]
#[IsReadOnly]
#[IsIdempotent]
final class LinksReadTool extends AbstractApiTool
{
    public const array COMPACT = [
        'link' => ['id', 'link_type_id', 'inward_id', 'outward_id', 'notes'],
        'type' => ['id', 'name', 'inward', 'outward', 'editable']
    ];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action'     => $schema->string()->enum(['list', 'get'])->required(),
            'kind'       => $schema->string()->enum(array_keys(self::COMPACT))->description('Default link.'),
            'id'         => $schema->integer()->description('For get.'),
            'journal_id' => $schema->integer()->description('link list: links of one journal.'),
            ...$this->readSchema($schema)
        ];
    }

    protected function run(Request $request): Response
    {
        $get  = 'get' === $this->actionArg($request, ['list', 'get']);
        $kind = $this->enumArg($request, 'kind', array_keys(self::COMPACT), 'link');

        $path = match (true) {
            'type' === $kind => $get ? sprintf('link-types/%s', $this->idArg($request)) : 'link-types',
            $get => sprintf('transaction-links/%s', $this->idArg($request)),
            null !== $request->get('journal_id') => sprintf('transaction-journals/%s/links', $this->idArg($request, 'journal_id')),
            default => 'transaction-links'
        };

        return $this->respondApi($this->api('GET', $path, $this->listQuery($request, [])), $request, self::COMPACT[$kind]);
    }
}
