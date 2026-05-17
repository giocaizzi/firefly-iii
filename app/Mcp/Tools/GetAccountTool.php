<?php

/*
 * GetAccountTool.php
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

use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Transformers\AccountTransformer;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use League\Fractal\Resource\Item;

#[Description('Get a single account by id or name. Provide either `id` or `name`; `id` wins when both are supplied.')]
#[IsReadOnly]
#[IsIdempotent]
final class GetAccountTool extends AbstractMcpTool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user       = auth()->user();
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);

        $id      = $request->get('id');
        $name    = $request->get('name');
        $account = null;

        if (null !== $id) {
            $account = $repository->find((int) $id);
        }
        if (null === $account && is_string($name) && '' !== $name) {
            $account = $repository->findByName($name, []);
        }
        if (null === $account) {
            return Response::error('Account not found.');
        }

        $transformer = app(AccountTransformer::class);
        $resource    = new Item($account, $transformer, 'accounts');

        $document = $this->jsonApiManager()->createData($resource)->toArray();

        return $this->respondJsonApi($document, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id'            => $schema->integer()->description('Account id. Wins over `name` when both are supplied.'),
            'name'          => $schema->string()->description('Exact account name; used when `id` is omitted.'),
            'verbose'       => $schema->boolean()->description('Return the raw JSON:API document instead of the reduced shape.'),
            'include_nulls' => $schema->boolean()->description('Preserve null-valued attributes in the reduced output.')
        ];
    }
}
