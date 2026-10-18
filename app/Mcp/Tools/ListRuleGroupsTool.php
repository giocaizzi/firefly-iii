<?php

/*
 * ListRuleGroupsTool.php
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

use FireflyIII\Mcp\Tools\Concerns\ShapesRules;
use FireflyIII\Repositories\RuleGroup\RuleGroupRepositoryInterface;
use FireflyIII\Transformers\RuleGroupTransformer;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Pagination\LengthAwarePaginator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Resource\Collection as FractalCollection;

#[Description('List rule groups in execution order (id, title, active, order).')]
#[IsReadOnly]
#[IsIdempotent]
final class ListRuleGroupsTool extends AbstractMcpTool
{
    use ShapesRules {
        shape as private decodedShape;
    }

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user       = auth()->user();
        $repository = app(RuleGroupRepositoryInterface::class);
        $repository->setUser($user);

        [$page, $limit] = $this->pageAndLimit($request);

        $collection = $repository->get();
        $count      = $collection->count();
        $groups     = $collection->slice(($page - 1) * $limit, $limit)->values();

        $paginator = new LengthAwarePaginator($groups, $count, $limit, $page);

        $transformer = app(RuleGroupTransformer::class);
        $resource    = new FractalCollection($groups, $transformer, 'rule_groups');
        $resource->setPaginator(new IlluminatePaginatorAdapter($paginator));

        $document = $this->jsonApiManager()->createData($resource)->toArray();

        return $this->respondJsonApi($document, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->pagingSchema($schema),
            ...$this->compactSchema($schema),
            ...$this->outputFlagsSchema($schema)
        ];
    }

    /**
     * @param array<string, mixed> $jsonApiDocument
     *
     * @return array<string, mixed>
     */
    protected function shape(array $jsonApiDocument, Request $request): array
    {
        $reduced = $this->decodedShape($jsonApiDocument, $request);
        if (!(bool) $request->get('compact', true) || !is_array($reduced['data'] ?? null)) {
            return $reduced;
        }
        $map             = ['id' => 'id', 'title' => 'title', 'description' => 'description', 'active' => 'active', 'order' => 'order'];
        $reduced['data'] = array_map(fn(array $row): array => $this->pick($row, $map, $this->includeNulls($request)), $reduced['data']);

        return $reduced;
    }
}
