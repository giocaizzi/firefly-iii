<?php

/*
 * CreateRuleGroupTool.php
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
use FireflyIII\Mcp\Tools\Concerns\ShapesRules;
use FireflyIII\Repositories\RuleGroup\RuleGroupRepositoryInterface;
use FireflyIII\Transformers\RuleGroupTransformer;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use League\Fractal\Resource\Item;

/**
 * Create a rule group (POST /api/v1/rule-groups semantics: unique title, active by default, last in order).
 */
#[Description('Create a rule group.')]
#[IsDestructive(false)]
final class CreateRuleGroupTool extends AbstractMcpTool
{
    use ShapesRules;

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user  = auth()->user();
        $title = trim((string) $request->get('title', ''));
        if ('' === $title || mb_strlen($title) > 100) {
            InvalidArgument::fail('title', 'title is required, 1-100 characters.');
        }
        if ($user->ruleGroups()->where('title', $title)->exists()) {
            InvalidArgument::fail('title', sprintf('A rule group titled "%s" already exists.', $title));
        }
        $data = [
            'title'       => $title,
            'description' => (string) $request->get('description', ''),
            'active'      => (bool) $request->get('active', true)
        ];
        if (null !== $request->get('order')) {
            $data['order'] = $this->positiveIntArg($request, 'order', 1);
        }

        $repository = app(RuleGroupRepositoryInterface::class);
        $repository->setUser($user);
        $group = $repository->store($data);

        Log::channel('audit')->info('MCP write', ['tool' => $this->name(), 'user_id' => $user->id, 'rule_group_id' => $group->id]);

        $resource = new Item($group->refresh(), app(RuleGroupTransformer::class), 'rule_groups');
        $document = $this->jsonApiManager()->createData($resource)->toArray();

        return $this->respondJsonApi($document, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title'       => $schema->string()->required()->description('1-100 chars, unique.'),
            'description' => $schema->string()->description('Description.'),
            'active'      => $schema->boolean()->description('Default true.'),
            'order'       => $schema->integer()->description('Execution position, from 1. Default last.'),
            ...$this->outputFlagsSchema($schema)
        ];
    }
}
