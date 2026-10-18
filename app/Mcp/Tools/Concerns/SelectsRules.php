<?php

/*
 * SelectsRules.php
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

use FireflyIII\Mcp\Support\InvalidArgument;
use FireflyIII\Models\Rule;
use FireflyIII\Repositories\Rule\RuleRepositoryInterface;
use FireflyIII\Repositories\RuleGroup\RuleGroupRepositoryInterface;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;

/**
 * `rule_id` or `rule_group_id` selection for test-rule / apply-rule. A group stands for its
 * active rules in order, as in the REST API.
 */
trait SelectsRules
{
    /**
     * @return array<string, mixed>
     */
    protected function ruleSelectionSchema(JsonSchema $schema): array
    {
        return [
            'rule_id'       => $schema->integer()->description('One rule (active or not).'),
            'rule_group_id' => $schema->integer()->description('All active rules of this group, in order. Ignored when rule_id is set.')
        ];
    }

    /**
     * @return Collection<int, Rule>
     */
    protected function selectedRules(User $user, Request $request): Collection
    {
        if (null !== $request->get('rule_id')) {
            $ruleId     = $this->positiveIntArg($request, 'rule_id', 0);
            $repository = app(RuleRepositoryInterface::class);
            $repository->setUser($user);
            $rule = $repository->find($ruleId);
            if (null === $rule) {
                InvalidArgument::fail('rule_id', sprintf('Rule #%d not found.', $ruleId));
            }

            return new Collection([$rule]);
        }
        if (null === $request->get('rule_group_id')) {
            InvalidArgument::fail('rule_id', 'Pass rule_id or rule_group_id.');
        }
        $groupId    = $this->positiveIntArg($request, 'rule_group_id', 0);
        $repository = app(RuleGroupRepositoryInterface::class);
        $repository->setUser($user);
        $group = $repository->find($groupId);
        if (null === $group) {
            InvalidArgument::fail('rule_group_id', sprintf('Rule group #%d not found.', $groupId));
        }
        $rules = $repository->getActiveRules($group);
        if (0 === $rules->count()) {
            InvalidArgument::fail('rule_group_id', sprintf('Rule group #%d has no active rules.', $groupId));
        }

        return $rules;
    }
}
