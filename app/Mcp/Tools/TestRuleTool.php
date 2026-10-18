<?php

/*
 * TestRuleTool.php
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

use FireflyIII\Mcp\Tools\Concerns\ProjectsTransactions;
use FireflyIII\Mcp\Tools\Concerns\SelectsRules;
use FireflyIII\Mcp\Tools\Concerns\ValidatesRuleInput;
use FireflyIII\Support\JsonApi\Enrichments\TransactionGroupEnrichment;
use FireflyIII\TransactionRules\Engine\RuleEngineInterface;
use FireflyIII\Transformers\TransactionGroupTransformer;
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

/**
 * Dry-run a rule (or the active rules of a group): which transactions would it act on?
 * Mirrors the REST API's rules/{id}/test and rule-groups/{id}/test; nothing is changed.
 */
#[Description('Dry-run a rule or rule group: the transactions it would change, with a count. Changes nothing.')]
#[IsReadOnly]
#[IsIdempotent]
final class TestRuleTool extends AbstractMcpTool
{
    use ProjectsTransactions;
    use SelectsRules;
    use ValidatesRuleInput;

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = auth()->user();
        [$page, $limit] = $this->pageAndLimit($request);
        $this->validateShapeArgs($request);
        $rules     = $this->selectedRules($user, $request);
        $operators = $this->scopeOperators($request);

        $engine = app(RuleEngineInterface::class);
        $engine->setUser($user);
        $engine->setRules($rules);
        foreach ($operators as $operator) {
            $engine->addOperator($operator);
        }
        $found = $engine->find()->values();
        $count = $found->count();

        $enrichment = new TransactionGroupEnrichment();
        $enrichment->setUser($user);
        $groups    = $enrichment->enrich($found->slice(($page - 1) * $limit, $limit)->values());
        $paginator = new LengthAwarePaginator($groups, $count, $limit, $page);

        $resource = new FractalCollection($groups, app(TransactionGroupTransformer::class), 'transactions');
        $resource->setPaginator(new IlluminatePaginatorAdapter($paginator));
        $document                  = $this->jsonApiManager()->createData($resource)->toArray();
        $document['meta']['count'] = $count;

        return $this->respondJsonApi($document, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->ruleSelectionSchema($schema),
            ...$this->scopeSchema($schema),
            ...$this->pagingSchema($schema),
            ...$this->transactionOutputSchema($schema)
        ];
    }
}
