<?php

/*
 * HealthTool.php
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

use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Support\Facades\AppConfiguration;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

use function Safe\file_get_contents;
use function Safe\preg_match;

/**
 * Connection self-check: versions, the authenticated user, enabled features and write access.
 *
 * `mcp.build` is the MCP build identifier: config `mcp.build` (env MCP_BUILD), else the git
 * commit of the checkout when a `.git` directory is present, else omitted.
 */
#[Description('Versions, user, enabled features and write access.')]
#[IsReadOnly]
#[IsIdempotent]
final class HealthTool extends AbstractMcpTool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user     = auth()->user();
        $group    = $user->userGroup;
        $canWrite = null !== $group && $user->hasRoleInGroupOrOwner($group, UserRoleEnum::MANAGE_TRANSACTIONS);
        $scopes   = $user->token()?->scopes;

        $data = [
            'firefly_version' => (string) config('firefly.version'),
            'mcp'             => ['server' => 'Firefly III MCP', 'build' => $this->buildId()],
            'user'            => ['id' => $user->id, 'email' => $user->email, 'user_group_id' => $user->user_group_id],
            'features'        => [
                'webhooks'          => true === config('firefly.feature_flags.webhooks')
                    && true === AppConfiguration::get('allow_webhooks', config('firefly.allow_webhooks'))->data,
                'exchange_rates'    => true === AppConfiguration::get('enable_exchange_rates', config('cer.enabled'))->data,
                'handle_debts'      => true === config('firefly.feature_flags.handle_debts'),
                'expression_engine' => true === config('firefly.feature_flags.expression_engine'),
                'export'            => true === config('firefly.feature_flags.export')
            ],
            'can_write'       => $canWrite,
            'token_scopes'    => is_array($scopes) ? array_values($scopes) : null
        ];
        if (!$this->includeNulls($request)) {
            $data['mcp'] = array_filter($data['mcp'], static fn(mixed $value): bool => null !== $value);
            $data        = array_filter($data, static fn(mixed $value): bool => null !== $value);
        }

        return Response::json(['data' => $data]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['include_nulls' => $schema->boolean()->description('Keep nulls/empties.')];
    }

    private function buildId(): null|string
    {
        $configured = config('mcp.build');
        if (is_string($configured) && '' !== trim($configured)) {
            return trim($configured);
        }
        $gitDir = base_path('.git');
        if (!is_dir($gitDir) || !is_file($gitDir . '/HEAD')) {
            return null;
        }
        $head = trim(file_get_contents($gitDir . '/HEAD'));
        if (1 === preg_match('/^[0-9a-f]{40}$/', $head)) {
            return substr($head, 0, 12);
        }
        if (1 !== preg_match('/^ref: (.+)$/', $head, $matches)) {
            return null;
        }
        $ref = $gitDir . '/' . $matches[1];
        if (is_file($ref)) {
            return substr(trim(file_get_contents($ref)), 0, 12);
        }

        return null;
    }
}
