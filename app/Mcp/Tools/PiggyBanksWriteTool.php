<?php

/*
 * PiggyBanksWriteTool.php
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
use FireflyIII\Repositories\PiggyBank\PiggyBankRepositoryInterface;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

use function Safe\preg_match;

#[Description('Create, update or delete a piggy bank, or add/remove money on one of its accounts.')]
#[IsDestructive]
final class PiggyBanksWriteTool extends AbstractApiTool
{
    private const array ACTIONS = ['create', 'update', 'delete', 'add', 'remove'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action'     => $schema->string()->enum(self::ACTIONS)->required(),
            'id'         => $schema->integer(),
            'account_id' => $schema->integer()->description('add/remove: one of the piggy bank\'s accounts.'),
            'amount'     => $schema->string()->description('add/remove: positive amount.'),
            ...$this->writeSchema(
                $schema,
                'name, accounts [{account_id, current_amount}], target_amount, start_date (create: required), target_date, transaction_currency_code, object_group_title, notes.'
            )
        ];
    }

    protected function run(Request $request): Response
    {
        $action = $this->actionArg($request, self::ACTIONS);
        if (in_array($action, ['add', 'remove'], true)) {
            return $this->moveMoney($request, 'add' === $action);
        }

        return $this->writeEntity($request, $action, 'piggy-banks', 'piggy bank', PiggyBanksReadTool::COMPACT, ['events' => 'events']);
    }

    /**
     * Same checks and repository calls as the web "add / remove money" forms: the amount must fit the account's
     * free balance and the target (add), or what was saved on that account (remove). Each change is a piggy bank event.
     */
    private function moveMoney(Request $request, bool $add): Response
    {
        $id     = (int) $this->idArg($request);
        $amount = trim((string) $request->get('amount'));
        if (1 !== preg_match('/^\d+(\.\d+)?$/', $amount) || 0 === bccomp($amount, '0')) {
            InvalidArgument::fail('amount', 'amount must be a positive number, e.g. "25.50".', ['received' => $request->get('amount')]);
        }

        /** @var User $user */
        $user       = auth()->user();
        $repository = app(PiggyBankRepositoryInterface::class);
        $repository->setUser($user);
        $piggyBank = $repository->find($id);
        if (null === $piggyBank) {
            throw new ApiFailure(sprintf('Piggy bank #%d not found.', $id));
        }
        $accountId = (int) $this->idArg($request, 'account_id');

        /** @var null|Account $account */
        $account = $piggyBank->accounts()->where('accounts.id', $accountId)->first();
        if (null === $account) {
            InvalidArgument::fail('account_id', sprintf('Account #%d is not linked to piggy bank #%d.', $accountId, $id), [
                'allowed' => $piggyBank->accounts()->pluck('accounts.id')->all()
            ]);
        }

        if ($add && !$repository->canAddAmount($piggyBank, $account, $amount)) {
            InvalidArgument::fail('amount', sprintf('Cannot add %s: more than the account\'s free balance or than is left to save.', $amount));
        }
        if (!$add && !$repository->canRemoveAmount($piggyBank, $account, $amount)) {
            InvalidArgument::fail('amount', sprintf('Cannot remove %s: more than was saved from this account.', $amount));
        }
        $add ? $repository->addAmount($piggyBank, $account, $amount) : $repository->removeAmount($piggyBank, $account, $amount);
        Preferences::mark();
        $this->audit(['action' => $add ? 'add' : 'remove', 'object' => 'piggy bank', 'id' => $id, 'account_id' => $accountId, 'amount' => $amount]);

        return $this->respondApi($this->api('GET', sprintf('piggy-banks/%d', $id)), $request, PiggyBanksReadTool::COMPACT);
    }
}
