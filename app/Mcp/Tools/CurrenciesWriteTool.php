<?php

/*
 * CurrenciesWriteTool.php
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

use FireflyIII\Mcp\Support\InvalidArgument;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Currencies: create/delete (admin), update, enable, disable, make primary. Exchange rates: create, update, delete.')]
#[IsDestructive]
final class CurrenciesWriteTool extends AbstractApiTool
{
    private const array ACTIONS = ['create', 'update', 'delete', 'enable', 'disable', 'primary'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(self::ACTIONS)->required(),
            'kind'   => $schema->string()->enum(['currency', 'rate'])->description('Default currency.'),
            'id'     => $schema->string()->description('Currency code, or rate id.'),
            ...$this->writeSchema($schema, 'currency: code, name, symbol, decimal_places, enabled; rate: from, to (codes), rate, date.')
        ];
    }

    protected function run(Request $request): Response
    {
        $action = $this->actionArg($request, self::ACTIONS);
        if ('rate' === $this->enumArg($request, 'kind', ['currency', 'rate'], 'currency')) {
            if (!in_array($action, ['create', 'update', 'delete'], true)) {
                InvalidArgument::fail('action', 'Exchange rates support create, update and delete.', ['allowed' => ['create', 'update', 'delete']]);
            }

            return $this->writeEntity($request, $action, 'exchange-rates', 'exchange rate', CurrenciesReadTool::COMPACT['rate']);
        }
        if (in_array($action, ['create', 'update', 'delete'], true)) {
            return $this->writeEntity($request, $action, 'currencies', 'currency', CurrenciesReadTool::COMPACT['currency'], [], true);
        }

        $code     = $this->idArg($request, 'id', true);
        $document = $this->api('POST', sprintf('currencies/%s/%s', $code, $action));
        $this->audit(['action' => $action, 'object' => 'currency', 'id' => $code]);

        return $this->respondApi($document, $request, CurrenciesReadTool::COMPACT['currency']);
    }
}
