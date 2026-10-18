<?php

/*
 * ApplyRuleTool.php
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
use FireflyIII\Mcp\Tools\Concerns\SelectsRules;
use FireflyIII\Mcp\Tools\Concerns\ValidatesRuleInput;
use FireflyIII\Models\Rule;
use FireflyIII\TransactionRules\Engine\RuleEngineInterface;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Throwable;

/**
 * Apply a rule (or the active rules of a group, in order) to existing transactions.
 *
 * Mirrors the REST API's rules/{id}/trigger and rule-groups/{id}/trigger: rules fire one by
 * one, so a rule's stop_processing does not stop the rest of the group (same as the web UI's
 * "execute"). Unlike the API, a scope is required so a call cannot rewrite the whole history
 * by accident. Run test-rule first to see the matches.
 */
#[Description('Apply a rule or rule group to existing transactions in a date range or id list. Returns how many changed.')]
#[IsDestructive]
final class ApplyRuleTool extends AbstractMcpTool
{
    use SelectsRules;
    use ValidatesRuleInput;

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user      = auth()->user();
        $rules     = $this->selectedRules($user, $request);
        $operators = $this->scopeOperators($request);
        if ([] === $operators) {
            InvalidArgument::fail('start', 'Pass a scope: start, end, transaction_ids or account_ids.');
        }

        $engine = app(RuleEngineInterface::class);
        $engine->setUser($user);
        $engine->setRules($rules);
        foreach ($operators as $operator) {
            $engine->addOperator($operator);
        }

        try {
            $engine->fire();
        } catch (Throwable $e) {
            Log::channel('audit')->error('MCP write failed', [
                'tool'      => $this->name(),
                'user_id'   => $user->id,
                'stage'     => 'rule-engine',
                'exception' => $e->getMessage()
            ]);

            return Response::error(sprintf('Failed to apply rules: %s', $e->getMessage()));
        }
        $affected = $engine->getResults();
        $ruleIds  = $rules
            ->map(static fn(Rule $rule): int => (int) $rule->id)
            ->values()
            ->all();

        Log::channel('audit')->info('MCP write', ['tool' => $this->name(), 'user_id' => $user->id, 'rule_ids' => $ruleIds, 'affected' => $affected]);

        return Response::json(['rule_ids' => $ruleIds, 'affected' => $affected]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->ruleSelectionSchema($schema),
            ...$this->scopeSchema($schema)
        ];
    }
}
