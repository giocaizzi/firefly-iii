<?php

/*
 * UpdateRuleGroupTool.php
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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use League\Fractal\Resource\Item;

/**
 * Update a rule group (PUT /api/v1/rule-groups/{id} semantics): only passed fields change;
 * a new `order` moves the group and renumbers the others.
 */
#[Description('Update a rule group: title, description, order, active. Only passed fields change.')]
#[IsDestructive]
#[IsIdempotent]
final class UpdateRuleGroupTool extends AbstractMcpTool
{
    use ShapesRules;

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user       = auth()->user();
        $id         = $this->positiveIntArg($request, 'rule_group_id', 0);
        $repository = app(RuleGroupRepositoryInterface::class);
        $repository->setUser($user);
        $group = 0 === $id ? null : $repository->find($id);
        if (null === $group) {
            InvalidArgument::fail('rule_group_id', 0 === $id ? 'rule_group_id is required.' : sprintf('Rule group #%d not found.', $id));
        }

        $data = [];
        if (null !== $request->get('title')) {
            $title = trim((string) $request->get('title'));
            if ('' === $title || mb_strlen($title) > 100) {
                InvalidArgument::fail('title', 'title must be 1-100 characters.');
            }
            if ($user->ruleGroups()->where('title', $title)->where('id', '!=', $group->id)->exists()) {
                InvalidArgument::fail('title', sprintf('A rule group titled "%s" already exists.', $title));
            }
            $data['title'] = $title;
        }
        if (null !== $request->get('description')) {
            $data['description'] = (string) $request->get('description');
        }
        if (null !== $request->get('active')) {
            $data['active'] = (bool) $request->get('active');
        }
        if (null !== $request->get('order')) {
            $data['order'] = $this->positiveIntArg($request, 'order', 1);
        }
        if ([] === $data) {
            InvalidArgument::fail('rule_group_id', 'Nothing to update: pass at least one of title, description, order, active.');
        }

        $group = $repository->update($group, $data);

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
            'rule_group_id' => $schema->integer()->required()->description('Rule group id.'),
            'title'         => $schema->string()->description('1-100 chars, unique.'),
            'description'   => $schema->string()->description('Description.'),
            'active'        => $schema->boolean()->description('Active.'),
            'order'         => $schema->integer()->description('Execution position, from 1.'),
            ...$this->outputFlagsSchema($schema)
        ];
    }
}
