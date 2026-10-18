<?php

/*
 * CurrenciesReadTool.php
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

#[Description('Currencies (by code; "primary" = primary currency) and exchange rates: list or get.')]
#[IsReadOnly]
#[IsIdempotent]
final class CurrenciesReadTool extends AbstractApiTool
{
    public const array COMPACT = [
        'currency' => ['id', 'code', 'name', 'symbol', 'decimal_places', 'enabled', 'primary'],
        'rate'     => ['id', 'from_currency_code', 'to_currency_code', 'rate', 'date']
    ];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'get'])->required(),
            'kind'   => $schema->string()->enum(array_keys(self::COMPACT))->description('Default currency.'),
            'id'     => $schema->string()->description('get: currency code, or rate id.'),
            'from'   => $schema->string()->description('rate list: from code (with to).'),
            'to'     => $schema->string()->description('rate list: to code.'),
            'start'  => $schema->string()->description('rate list with from/to: YYYY-MM-DD.'),
            'end'    => $schema->string()->description('rate list with from/to: YYYY-MM-DD.'),
            ...$this->readSchema($schema)
        ];
    }

    protected function run(Request $request): Response
    {
        $action = $this->actionArg($request, ['list', 'get']);
        $kind   = $this->enumArg($request, 'kind', array_keys(self::COMPACT), 'currency');
        $query  = $this->listQuery($request);
        $get    = 'get' === $action;

        $path = match (true) {
            'currency' === $kind => $get ? sprintf('currencies/%s', $this->idArg($request, 'id', true)) : 'currencies',
            $get => sprintf('exchange-rates/%s', $this->idArg($request)),
            null !== $request->get('from') || null !== $request->get('to') => sprintf(
                'exchange-rates/%s/%s',
                $this->idArg($request, 'from', true),
                $this->idArg($request, 'to', true)
            ),
            default => 'exchange-rates'
        };

        return $this->respondApi($this->api('GET', $path, $query), $request, self::COMPACT[$kind]);
    }
}
