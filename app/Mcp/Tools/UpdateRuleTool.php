<?php

/*
 * UpdateRuleTool.php
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

use FireflyIII\Models\Rule;
use FireflyIII\Repositories\Rule\RuleRepositoryInterface;
use FireflyIII\Support\Request\GetRuleConfiguration;
use FireflyIII\Transformers\RuleTransformer;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use League\Fractal\Resource\Item;
use Throwable;

use function Safe\json_encode;

/**
 * Update an existing rule: its meta fields and/or its triggers and actions.
 *
 * Passing `triggers` or `actions` REPLACES the rule's existing set (the repository
 * deletes and recreates them), mirroring the REST API's update semantics. Only the
 * fields present in the request are touched; omitted fields are left as-is.
 */
#[Description('Update an existing rule (title, flags, triggers, actions). Passing triggers/actions replaces the existing set; omitted fields are left unchanged.')]
#[IsDestructive]
#[IsIdempotent]
final class UpdateRuleTool extends AbstractMcpTool
{
    use GetRuleConfiguration;

    public function handle(Request $request): Response
    {
        $input  = $request->toArray();
        $ruleId = array_key_exists('rule_id', $input) ? (int) $input['rule_id'] : 0;
        if ($ruleId <= 0) {
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

        $data = $this->buildUpdateData($input);
        if ([] === $data) {
            return Response::error('Nothing to update: provide at least one of title, description, active, strict, stop_processing, trigger, triggers, actions.');
        }

        if (null !== ($invalid = $this->validateData($data))) {
            $this->auditFailure('validation', 'Validation failed');

            return $invalid;
        }

        try {
            $rule = $repository->update($rule, $data);
        } catch (Throwable $e) {
            $this->auditFailure('repository', $e->getMessage());

            return Response::error(sprintf('Failed to update rule #%d: %s', $ruleId, $e->getMessage()));
        }

        Log::channel('audit')->info('MCP write', [
            'tool'    => $this->name(),
            'user_id' => auth()->id(),
            'rule_id' => $rule->id
        ]);

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
        $trigger = $schema->object([
            'type'            => $schema->string()->required()->description('Trigger type, e.g. "description_contains", "amount_more".'),
            'value'           => $schema->string()->description('Trigger value (required by most trigger types).'),
            'prohibited'      => $schema->boolean()->description('Negate the trigger (NOT).'),
            'active'          => $schema->boolean()->description('Whether this trigger is active (default true).'),
            'stop_processing' => $schema->boolean()->description('Stop evaluating further triggers once this one matches.')
        ]);
        $action  = $schema->object([
            'type'            => $schema->string()->required()->description('Action type, e.g. "set_category", "convert_transfer".'),
            'value'           => $schema->string()->description('Action value (required by most action types).'),
            'active'          => $schema->boolean()->description('Whether this action is active (default true).'),
            'stop_processing' => $schema->boolean()->description('Stop running further actions once this one runs.')
        ]);

        return [
            'rule_id'         => $schema->integer()->required()->description('Numeric ID of the rule to update.'),
            'title'           => $schema->string()->description('New rule title (1-100 chars).'),
            'description'     => $schema->string()->description('New rule description.'),
            'active'          => $schema->boolean()->description('Enable or disable the rule.'),
            'strict'          => $schema->boolean()->description('Strict mode: all triggers must match (true) vs any (false).'),
            'stop_processing' => $schema->boolean()->description('Stop processing later rules in the group once this rule fires.'),
            'trigger'         => $schema->string()->enum(['store-journal', 'update-journal', 'manual-activation'])->description('When the rule fires.'),
            'triggers'        => $schema->array()->items($trigger)->description('Replace the rule triggers with this set.'),
            'actions'         => $schema->array()->items($action)->description('Replace the rule actions with this set.'),
            'verbose'         => $schema->boolean()->description('Return the full JSON:API document instead of the reduced shape.'),
            'include_nulls'   => $schema->boolean()->description('Keep null attribute values in the reduced shape.')
        ];
    }

    /**
     * Map the MCP input onto the array shape RuleRepository::update() expects, dropping
     * fields the caller did not send so the update stays partial.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function buildUpdateData(array $input): array
    {
        $data = [];
        if (array_key_exists('title', $input)) {
            $data['title'] = (string) $input['title'];
        }
        if (array_key_exists('description', $input)) {
            $data['description'] = (string) $input['description'];
        }
        foreach (['active', 'strict', 'stop_processing'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $data[$flag] = (bool) $input[$flag];
            }
        }
        if (array_key_exists('trigger', $input)) {
            $data['trigger'] = (string) $input['trigger'];
        }
        if (array_key_exists('triggers', $input) && is_array($input['triggers'])) {
            $data['triggers'] = array_map(static fn(array $t): array => [
                'type'            => (string) ($t['type'] ?? ''),
                'value'           => array_key_exists('value', $t) ? (string) $t['value'] : '',
                'prohibited'      => (bool) ($t['prohibited'] ?? false),
                'active'          => (bool) ($t['active'] ?? true),
                'stop_processing' => (bool) ($t['stop_processing'] ?? false)
            ], $input['triggers']);
        }
        if (array_key_exists('actions', $input) && is_array($input['actions'])) {
            $data['actions'] = array_map(static fn(array $a): array => [
                'type'            => (string) ($a['type'] ?? ''),
                'value'           => array_key_exists('value', $a) ? (string) $a['value'] : '',
                'active'          => (bool) ($a['active'] ?? true),
                'stop_processing' => (bool) ($a['stop_processing'] ?? false)
            ], $input['actions']);
        }

        return $data;
    }

    /**
     * Validate the trigger/action types and meta fields against the same vocabulary the
     * REST API uses, without the route-bound title-uniqueness rule (no route here).
     *
     * @param array<string, mixed> $data
     */
    private function validateData(array $data): null|Response
    {
        $rules     = [
            'title'                      => 'min:1|max:100',
            'description'                => ['nullable', 'max:32768'],
            'triggers.*.type'            => 'required|in:'.implode(',', $this->getTriggers()),
            'triggers.*.value'           => 'nullable|max:1024',
            'actions.*.type'             => 'required|in:'.implode(',', array_keys((array) config('firefly.rule-actions'))),
            'actions.*.value'            => 'nullable|max:1024'
        ];
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return Response::error((string) json_encode($validator->errors()->toArray()));
        }

        return null;
    }

    private function auditFailure(string $stage, string $message): void
    {
        Log::channel('audit')->error('MCP write failed', [
            'tool'      => $this->name(),
            'user_id'   => auth()->id(),
            'stage'     => $stage,
            'exception' => $message
        ]);
    }
}
