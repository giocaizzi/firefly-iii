<?php

/*
 * GetTransactionTool.php
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

use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Mcp\Tools\Concerns\ProjectsTransactions;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\Support\JsonApi\Enrichments\TransactionGroupEnrichment;
use FireflyIII\Transformers\TransactionGroupTransformer;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use League\Fractal\Resource\Item;

#[Description('Get one transaction group by group id.')]
#[IsReadOnly]
#[IsIdempotent]
final class GetTransactionTool extends AbstractMcpTool
{
    use ProjectsTransactions;

    public function handle(Request $request): Response
    {
        $this->validateShapeArgs($request);
        /** @var User $user */
        $user       = auth()->user();
        $repository = app(TransactionGroupRepositoryInterface::class);
        $repository->setUser($user);

        $group = $repository->find((int) $request->get('id'));
        if (null === $group) {
            return Response::error('Transaction not found.');
        }

        // Use the GroupCollector so the transformer sees the same enriched journal shape
        // it expects from the REST API.
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($user)->setTransactionGroup($group)->withAPIInformation();

        $selected = $collector->getGroups()->first();
        if (null === $selected) {
            return Response::error('Transaction not found.');
        }

        $enrichment = new TransactionGroupEnrichment();
        $enrichment->setUser($user);
        $selected = $enrichment->enrichSingle($selected);

        $transformer = app(TransactionGroupTransformer::class);
        $resource    = new Item($selected, $transformer, 'transactions');

        $document = $this->jsonApiManager()->createData($resource)->toArray();

        return $this->respondJsonApi($document, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Group id: a row\'s group_id, else its id.'),
            ...$this->transactionOutputSchema($schema)
        ];
    }
}
