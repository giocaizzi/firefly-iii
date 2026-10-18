<?php

/*
 * CreateRuleTool.php
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

use FireflyIII\Mcp\Support\HtmlText;
use FireflyIII\Mcp\Support\InvalidArgument;
use FireflyIII\Mcp\Tools\Concerns\ShapesRules;
use FireflyIII\Mcp\Tools\Concerns\ValidatesRuleInput;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Repositories\Rule\RuleRepositoryInterface;
use FireflyIII\Transformers\RuleTransformer;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use League\Fractal\Resource\Item;
use Throwable;

/**
 * Create a rule, with the REST API's semantics (POST /api/v1/rules): the rule group is given
 * by id or exact title, titles are unique per user, `strict` defaults to false (any trigger
 * matches), `trigger` to store-journal.
 */
#[Description('Create a rule in a rule group (strict = all triggers must match).')]
#[IsDestructive(false)]
final class CreateRuleTool extends AbstractMcpTool
{
    use ShapesRules;
    use ValidatesRuleInput;

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user  = auth()->user();
        $title = trim((string) $request->get('title', ''));
        if ('' === $title || mb_strlen($title) > 100) {
            InvalidArgument::fail('title', 'title is required, 1-100 characters.');
        }
        if ($user->rules()->where('title', $title)->exists()) {
            InvalidArgument::fail('title', sprintf('A rule titled "%s" already exists.', $title));
        }
        $group    = $this->ruleGroup($user, $request);
        $triggers = $this->validatedTriggers($request->get('triggers'));
        $actions  = $this->validatedActions($request->get('actions'));
        $moment   = $this->enumArg($request, 'trigger', ['store-journal', 'update-journal', 'manual-activation'], 'store-journal');

        $data = [
            'rule_group_id'   => (int) $group->id,
            'title'           => $title,
            'description'     => HtmlText::decode((string) $request->get('description', '')),
            'trigger'         => $moment,
            'strict'          => (bool) $request->get('strict', false),
            'active'          => (bool) $request->get('active', true),
            'stop_processing' => (bool) $request->get('stop_processing', false),
            'triggers'        => $triggers,
            'actions'         => $actions
        ];
        if (null !== $request->get('order')) {
            $data['order'] = $this->positiveIntArg($request, 'order', 1);
        }

        $repository = app(RuleRepositoryInterface::class);
        $repository->setUser($user);

        try {
            $rule = $repository->store($data);
        } catch (Throwable $e) {
            Log::channel('audit')->error('MCP write failed', [
                'tool'      => $this->name(),
                'user_id'   => $user->id,
                'stage'     => 'repository',
                'exception' => $e->getMessage()
            ]);

            return Response::error(sprintf('Failed to create rule: %s', $e->getMessage()));
        }

        Log::channel('audit')->info('MCP write', ['tool' => $this->name(), 'user_id' => $user->id, 'rule_id' => $rule->id]);

        $resource = new Item($rule, app(RuleTransformer::class), 'rules');
        $document = $this->jsonApiManager()->createData($resource)->toArray();

        return $this->respondJsonApi($document, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title'            => $schema->string()->required()->description('1-100 chars, unique.'),
            'rule_group_id'    => $schema->integer()->description('Rule group id (or rule_group_title).'),
            'rule_group_title' => $schema->string()->description('Exact rule group title.'),
            'description'      => $schema->string()->description('Description.'),
            'trigger'          => $schema
                ->string()
                ->enum(['store-journal', 'update-journal', 'manual-activation'])
                ->description('When it fires. Default store-journal.'),
            'strict'           => $schema->boolean()->description('true = all triggers must match; default false = any.'),
            'active'           => $schema->boolean()->description('Default true.'),
            'stop_processing'  => $schema->boolean()->description('Stop later rules in the group after this one fires.'),
            'order'            => $schema->integer()->description('Position in the group, from 1. Default last.'),
            ...$this->ruleItemsSchema($schema),
            ...$this->outputFlagsSchema($schema)
        ];
    }

    private function ruleGroup(User $user, Request $request): RuleGroup
    {
        if (null !== $request->get('rule_group_id')) {
            $id = $this->positiveIntArg($request, 'rule_group_id', 0);

            /** @var null|RuleGroup $group */
            $group = $user->ruleGroups()->find($id);
            if (null === $group) {
                InvalidArgument::fail('rule_group_id', sprintf('Rule group #%d not found.', $id));
            }

            return $group;
        }
        $title = trim((string) $request->get('rule_group_title', ''));
        if ('' === $title) {
            InvalidArgument::fail('rule_group_id', 'Pass rule_group_id or rule_group_title (see list-rule-groups).');
        }

        /** @var null|RuleGroup $group */
        $group = $user->ruleGroups()->where('title', $title)->first();
        if (null === $group) {
            InvalidArgument::fail('rule_group_title', sprintf('No rule group titled "%s"; create it with create-rule-group.', $title));
        }

        return $group;
    }
}
