<?php

/*
 * ValidatesRuleInput.php
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

namespace FireflyIII\Mcp\Tools\Concerns;

use Carbon\Carbon;
use FireflyIII\Mcp\Resources\SearchHelpResource;
use FireflyIII\Mcp\Support\InvalidArgument;
use FireflyIII\Rules\IsValidActionExpression;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;

use function Safe\preg_match;

/**
 * Rule input for the MCP rule tools, validated with the REST API's vocabulary and value rules
 * (config search.operators for triggers, firefly.rule-actions for actions).
 *
 * A trigger type may carry a "-" prefix (as Firefly stores prohibited triggers); it is the same
 * as `prohibited: true`. Rejections are InvalidArgument errors naming the offending field.
 */
trait ValidatesRuleInput
{
    /**
     * @return array<string, mixed>
     */
    protected function ruleItemsSchema(JsonSchema $schema): array
    {
        $trigger = $schema->object([
            'type'            => $schema->string()->required()->description('Search operator, e.g. description_contains; "-" prefix = prohibited.'),
            'value'           => $schema->string()->description('Required unless the operator takes no value.'),
            'prohibited'      => $schema->boolean()->description('Negate (NOT).'),
            'active'          => $schema->boolean()->description('Default true.'),
            'stop_processing' => $schema->boolean()->description('Stop checking later triggers after a match.')
        ]);
        $action = $schema->object([
            'type'            => $schema->string()->required()->description('e.g. set_category, add_tag.'),
            'value'           => $schema->string()->description('Required for actions that take a value.'),
            'active'          => $schema->boolean()->description('Default true.'),
            'stop_processing' => $schema->boolean()->description('Stop after this action.')
        ]);

        return [
            'triggers' => $schema->array()->items($trigger)->description(sprintf('At least one active trigger. Operators: %s.', SearchHelpResource::URI)),
            'actions'  => $schema->array()->items($action)->description('At least one active action.')
        ];
    }

    /**
     * Optional scope arguments shared by test-rule and apply-rule, as rule-engine operators.
     *
     * @return list<array{type: string, value: string}>
     */
    protected function scopeOperators(Request $request): array
    {
        $start = $this->dateArg($request, 'start');
        $end   = $this->dateArg($request, 'end');
        if ($start instanceof Carbon && $end instanceof Carbon && $start->gt($end)) {
            InvalidArgument::fail('start', 'start must not be after end.');
        }
        $operators = [];
        if (null !== $start) {
            $operators[] = ['type' => 'date_after', 'value' => $start->format('Y-m-d')];
        }
        if (null !== $end) {
            $operators[] = ['type' => 'date_before', 'value' => $end->format('Y-m-d')];
        }
        $ids = $this->idListArg($request, 'transaction_ids');
        if ([] !== $ids) {
            $operators[] = ['type' => 'id', 'value' => implode(',', $ids)];
        }
        $accounts = $this->idListArg($request, 'account_ids');
        if ([] !== $accounts) {
            $operators[] = ['type' => 'account_id', 'value' => implode(',', $accounts)];
        }

        return $operators;
    }

    /**
     * @return array<string, mixed>
     */
    protected function scopeSchema(JsonSchema $schema): array
    {
        return [
            'start'           => $schema->string()->format('date')->description('Only transactions on or after this date.'),
            'end'             => $schema->string()->format('date')->description('Only transactions on or before this date.'),
            'transaction_ids' => $schema->array()->items($schema->integer())->description('Only these transactions (group ids).'),
            'account_ids'     => $schema->array()->items($schema->integer())->description('Only transactions touching these accounts.')
        ];
    }

    /**
     * Normalise and validate actions.
     *
     * @return list<array{type: string, value: string, active: bool, stop_processing: bool}>
     */
    protected function validatedActions(mixed $raw): array
    {
        $allowed = array_keys((array) config('firefly.rule-actions'));
        $actions = [];
        foreach ($this->itemList($raw, 'actions') as $index => $action) {
            $actions[] = [
                'type'            => strtolower(trim((string) ($action['type'] ?? ''))),
                'value'           => array_key_exists('value', $action) && null !== $action['value'] ? (string) $action['value'] : '',
                'active'          => (bool) ($action['active'] ?? true),
                'stop_processing' => (bool) ($action['stop_processing'] ?? false)
            ];
            $type = $actions[$index]['type'];
            if (!in_array($type, $allowed, true)) {
                InvalidArgument::fail(sprintf('actions.%d.type', $index), sprintf('Unknown action type "%s".', $type), ['allowed' => $allowed]);
            }
            if ('' === trim($actions[$index]['value']) && in_array($type, (array) config('firefly.context-rule-actions'), true)) {
                InvalidArgument::fail(sprintf('actions.%d.value', $index), sprintf('Action "%s" needs a value.', $type));
            }
        }
        $this->atLeastOneActive($actions, 'actions');
        $this->validateValues(['actions' => $actions], [
            'actions.*.value' => ['nullable', 'max:1024', new IsValidActionExpression(), 'ruleActionValue']
        ]);

        return $actions;
    }

    /**
     * Normalise and validate triggers ("-type" = prohibited).
     *
     * @return list<array{type: string, value: string, prohibited: bool, active: bool, stop_processing: bool}>
     */
    protected function validatedTriggers(mixed $raw): array
    {
        $operators = (array) config('search.operators');
        $triggers  = [];
        foreach ($this->itemList($raw, 'triggers') as $index => $trigger) {
            $type       = strtolower(trim((string) ($trigger['type'] ?? '')));
            $prohibited = (bool) ($trigger['prohibited'] ?? false) || str_starts_with($type, '-');
            $type       = ltrim($type, '-');
            if (!array_key_exists($type, $operators)) {
                InvalidArgument::fail(
                    sprintf('triggers.%d.type', $index),
                    sprintf('Unknown trigger type "%s"; triggers use the search operators, see %s.', $type, SearchHelpResource::URI)
                );
            }
            $value = array_key_exists('value', $trigger) && null !== $trigger['value'] ? (string) $trigger['value'] : '';
            if ('' === trim($value) && true === ($operators[$type]['needs_context'] ?? true)) {
                InvalidArgument::fail(sprintf('triggers.%d.value', $index), sprintf('Trigger "%s" needs a value.', $type));
            }
            $triggers[] = [
                'type'            => $type,
                'value'           => $value,
                'prohibited'      => $prohibited,
                'active'          => (bool) ($trigger['active'] ?? true),
                'stop_processing' => (bool) ($trigger['stop_processing'] ?? false)
            ];
        }
        $this->atLeastOneActive($triggers, 'triggers');
        $this->validateValues(['triggers' => $triggers], ['triggers.*.value' => ['nullable', 'max:1024', 'ruleTriggerValue']]);

        return $triggers;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function atLeastOneActive(array $items, string $field): void
    {
        foreach ($items as $item) {
            if (true === $item['active']) {
                return;
            }
        }
        InvalidArgument::fail($field, sprintf('A rule needs at least one active %s.', rtrim($field, 's')));
    }

    /**
     * @return list<int>
     */
    private function idListArg(Request $request, string $key): array
    {
        $raw = $request->get($key);
        if (null === $raw) {
            return [];
        }
        if (!is_array($raw)) {
            InvalidArgument::fail($key, sprintf('%s must be a list of ids.', $key), ['received' => $raw]);
        }
        $ids = [];
        foreach ($raw as $id) {
            if (is_string($id) && 1 === preg_match('/^\d+$/', trim($id))) {
                $id = (int) trim($id);
            }
            if (!is_int($id) || $id < 1) {
                InvalidArgument::fail($key, sprintf('%s must be a list of positive integers.', $key), ['received' => $raw]);
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function itemList(mixed $raw, string $field): array
    {
        if (!is_array($raw) || [] === $raw || !array_is_list($raw)) {
            InvalidArgument::fail($field, sprintf('%s must be a non-empty list.', $field));
        }
        foreach ($raw as $index => $item) {
            if (!is_array($item)) {
                InvalidArgument::fail(sprintf('%s.%d', $field, $index), sprintf('Each %s entry must be an object with a type.', rtrim($field, 's')));
            }
        }

        /** @var list<array<string, mixed>> $raw */
        return $raw;
    }

    /**
     * Run Firefly's value validators (ruleTriggerValue, ruleActionValue, expressions).
     *
     * @param array<string, mixed>               $data
     * @param array<string, array<mixed>|string> $rules
     */
    private function validateValues(array $data, array $rules): void
    {
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();
            $field  = (string) array_key_first($errors);
            InvalidArgument::fail($field, (string) $validator->errors()->first(), ['errors' => $errors]);
        }
    }
}
