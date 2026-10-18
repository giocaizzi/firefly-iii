<?php

/*
 * WebhooksWriteTool.php
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

use FireflyIII\Support\Facades\AppConfiguration;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Create, update or delete a webhook (needs webhooks enabled on the instance).')]
#[IsDestructive]
final class WebhooksWriteTool extends AbstractApiTool
{
    private const array COMPACT = ['id', 'title', 'active', 'url', 'triggers', 'responses', 'deliveries'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['create', 'update', 'delete'])->required(),
            'id'     => $schema->integer()->description('For update/delete.'),
            ...$this->writeSchema(
                $schema,
                'title, url, active, triggers, responses, deliveries (lists, e.g. ["STORE_TRANSACTION"], ["TRANSACTIONS"], ["JSON"]; update needs them too).'
            )
        ];
    }

    protected function run(Request $request): Response
    {
        if (false === AppConfiguration::get('allow_webhooks', config('firefly.allow_webhooks'))->data) {
            return Response::error('Webhooks are not enabled on this Firefly III instance.');
        }
        $action = $this->actionArg($request, ['create', 'update', 'delete']);

        return $this->writeEntity($request, $action, 'webhooks', 'webhook', self::COMPACT, ['messages' => 'messages']);
    }
}
