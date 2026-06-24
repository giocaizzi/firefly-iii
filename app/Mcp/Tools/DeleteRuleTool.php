<?php

/*
 * DeleteRuleTool.php
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

use FireflyIII\Repositories\Rule\RuleRepositoryInterface;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Throwable;

/**
 * Permanently delete a rule (and its triggers and actions) by its id.
 */
#[Description('Delete a rule by its id. The rule and all of its triggers and actions are permanently removed.')]
#[IsDestructive]
final class DeleteRuleTool extends AbstractMcpTool
{
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

        try {
            $repository->destroy($rule);
        } catch (Throwable $e) {
            Log::channel('audit')->error('MCP write failed', [
                'tool'      => $this->name(),
                'user_id'   => auth()->id(),
                'stage'     => 'repository',
                'exception' => $e->getMessage()
            ]);

            return Response::error(sprintf('Failed to delete rule #%d: %s', $ruleId, $e->getMessage()));
        }

        Log::channel('audit')->info('MCP write', [
            'tool'    => $this->name(),
            'user_id' => auth()->id(),
            'rule_id' => $ruleId
        ]);

        return Response::text(sprintf('Deleted rule #%d.', $ruleId));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'rule_id' => $schema->integer()->required()->description('Numeric ID of the rule to delete.')
        ];
    }
}
