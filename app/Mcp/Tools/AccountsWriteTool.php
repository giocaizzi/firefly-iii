<?php

/*
 * AccountsWriteTool.php
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

use FireflyIII\Mcp\Support\ApiFailure;
use FireflyIII\Mcp\Support\InvalidArgument;
use FireflyIII\Models\Account;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Create or update an account, or merge one into another of the same type (moves its transactions, then deletes it; confirm=true, else a preview).')]
#[IsDestructive]
final class AccountsWriteTool extends AbstractApiTool
{
    private const array ACTIONS = ['create', 'update', 'merge'];

    private const array COMPACT = ['id', 'name', 'type', 'account_role', 'currency_code', 'current_balance', 'iban', 'active'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(self::ACTIONS)->required(),
            'id'     => $schema->integer()->description('update / merge source.'),
            'into'   => $schema->integer()->description('merge: target account id.'),
            ...$this->writeSchema(
                $schema,
                'name, type (asset|expense|revenue|liability), account_role (defaultAsset|sharedAsset|savingAsset|ccAsset|cashWalletAsset), currency_code, active, '
                . 'include_net_worth, iban, bic, account_number, opening_balance, opening_balance_date, virtual_balance, notes; '
                . 'liabilities: liability_type, liability_direction, interest, interest_period.'
            )
        ];
    }

    protected function run(Request $request): Response
    {
        $action = $this->actionArg($request, self::ACTIONS);
        if ('merge' !== $action) {
            return $this->writeEntity($request, $action, 'accounts', 'account', self::COMPACT);
        }

        /** @var User $user */
        $user       = auth()->user();
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);
        $from = $this->findAccount($repository, (int) $this->idArg($request));
        $into = $this->findAccount($repository, (int) $this->idArg($request, 'into'));
        if ($from->id === $into->id) {
            InvalidArgument::fail('into', 'into must be a different account.');
        }
        // the web "delete account" form only offers accounts of the same type to move transactions to
        if ($from->account_type_id !== $into->account_type_id) {
            InvalidArgument::fail('into', sprintf(
                'Account #%d is a "%s", account #%d a "%s"; merge needs the same type.',
                $from->id,
                $from->accountType->type,
                $into->id,
                $into->accountType->type
            ));
        }

        return $this->mergeEntity(
            $request,
            'account',
            ['id' => $from->id, 'name' => $from->name],
            ['id' => $into->id, 'name' => $into->name],
            $from->transactions()->distinct()->count('transaction_journal_id'),
            static function () use ($repository, $from, $into): void {
                $repository->destroy($from, $into);
            }
        );
    }

    private function findAccount(AccountRepositoryInterface $repository, int $id): Account
    {
        return $repository->find($id) ?? throw new ApiFailure(sprintf('Account #%d not found.', $id));
    }
}
