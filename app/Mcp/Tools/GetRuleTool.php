<?php

/*
 * GetRuleTool.php
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
use FireflyIII\Repositories\Rule\RuleRepositoryInterface;
use FireflyIII\Transformers\RuleTransformer;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use League\Fractal\Resource\Item;

#[Description('Get one rule with its full triggers and actions.')]
#[IsReadOnly]
#[IsIdempotent]
final class GetRuleTool extends AbstractMcpTool
{
    use ShapesRules;

    public function handle(Request $request): Response
    {
        $ruleId = $this->positiveIntArg($request, 'rule_id', 0);
        if (0 === $ruleId) {
            return Response::error('rule_id is required and must be a positive integer.');
        }

        /** @var User $user */
        $user       = auth()->user();
        $repository = app(RuleRepositoryInterface::class);
        $repository->setUser($user);

        $rule = $repository->find($ruleId);
        if (null === $rule) {
            return Response::error(sprintf('Rule #%d not found.', $ruleId));
        }

        $transformer = app(RuleTransformer::class);
        $resource    = new Item($rule, $transformer, 'rules');
        $document    = $this->jsonApiManager()->createData($resource)->toArray();

        return $this->respondJsonApi($document, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'rule_id' => $schema->integer()->required()->description('Rule id.'),
            ...$this->outputFlagsSchema($schema)
        ];
    }
}
