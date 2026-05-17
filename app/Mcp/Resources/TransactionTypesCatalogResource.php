<?php

/**
 * TransactionTypesCatalogResource.php
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

namespace FireflyIII\Mcp\Resources;

use FireflyIII\Mcp\Support\JsonApiReducer;
use FireflyIII\Models\TransactionType;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

/**
 * Static catalog of every transaction type known to this Firefly III instance (WIP_MCP D-011, §5.1).
 *
 * TransactionType has no public Fractal transformer, so a thin inline JSON:API shape is fed
 * through JsonApiReducer (D-034). The table only stores the type label beyond timestamps.
 */
#[Uri('firefly-iii://catalogs/transaction-types')]
#[MimeType('application/json')]
#[Description('All transaction types (withdrawal, deposit, transfer, etc.).')]
final class TransactionTypesCatalogResource extends Resource
{
    public function handle(Request $request): Response
    {
        $items = TransactionType::all()->map(static fn(TransactionType $type): array => [
            'type'       => 'transaction_types',
            'id'         => (string) $type->id,
            'attributes' => [
                'type' => $type->type
            ]
        ])->all();

        $document = ['data' => $items];
        $reduced  = app(JsonApiReducer::class)->reduce($document);

        return Response::text(json_encode($reduced, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
