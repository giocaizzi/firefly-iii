<?php

/*
 * DeleteAccountTool.php
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

use FireflyIII\Models\Account;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Throwable;

/**
 * Delete an account by its id.
 *
 * When `move_to_account_id` is supplied, the account's transactions are re-homed onto
 * that account before deletion; otherwise the account's transactions (and any recurring
 * transactions using it) are removed. Use the move target to merge phantom/duplicate
 * accounts into the correct one without losing history.
 */
#[Description('Delete an account by its id. Optionally pass move_to_account_id to move this account\'s transactions onto another account before deleting (merge); without it, the account\'s transactions are deleted.')]
#[IsDestructive]
final class DeleteAccountTool extends AbstractMcpTool
{
    public function handle(Request $request): Response
    {
        $input     = $request->toArray();
        $accountId = array_key_exists('account_id', $input) ? (int) $input['account_id'] : 0;
        if ($accountId <= 0) {
            return Response::error('account_id is required and must be a positive integer.');
        }

        /** @var User $user */
        $user       = auth()->user();
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);

        $account = $repository->find($accountId);
        if (null === $account) {
            return Response::error(sprintf('Account #%d not found.', $accountId));
        }

        $moveTo   = null;
        $moveToId = array_key_exists('move_to_account_id', $input) ? (int) $input['move_to_account_id'] : 0;
        if ($moveToId > 0) {
            if ($moveToId === $accountId) {
                return Response::error('move_to_account_id must differ from account_id.');
            }
            $moveTo = $repository->find($moveToId);
            if (null === $moveTo) {
                return Response::error(sprintf('Move-to account #%d not found.', $moveToId));
            }
        }

        try {
            $repository->destroy($account, $moveTo);
        } catch (Throwable $e) {
            Log::channel('audit')->error('MCP write failed', [
                'tool'      => $this->name(),
                'user_id'   => auth()->id(),
                'stage'     => 'repository',
                'exception' => $e->getMessage()
            ]);

            return Response::error(sprintf('Failed to delete account #%d: %s', $accountId, $e->getMessage()));
        }

        Log::channel('audit')->info('MCP write', [
            'tool'               => $this->name(),
            'user_id'            => auth()->id(),
            'account_id'         => $accountId,
            'move_to_account_id' => $moveTo instanceof Account ? $moveTo->id : null
        ]);

        $message = $moveTo instanceof Account
            ? sprintf('Deleted account #%d; its transactions were moved to account #%d.', $accountId, $moveTo->id)
            : sprintf('Deleted account #%d.', $accountId);

        return Response::text($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id'         => $schema->integer()->required()->description('Numeric ID of the account to delete.'),
            'move_to_account_id' => $schema->integer()->description('Optional account id to receive this account\'s transactions before deletion (merge).')
        ];
    }
}
