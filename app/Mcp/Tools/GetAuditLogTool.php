<?php

/*
 * GetAuditLogTool.php
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

use FireflyIII\Models\AuditLogEntry;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\AuditLogEntry\ALERepositoryInterface;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Read the audit-log history (before/after change records) for a transaction.
 *
 * Firefly III records structured audit entries when rules or conversions mutate a
 * transaction. This returns both the group-level entries and the entries on the
 * transaction's underlying journal(s), ordered oldest-first.
 */
#[Description("Read the audit-log change history for a transaction (rule-driven and conversion changes, with before/after values).")]
#[IsReadOnly]
#[IsIdempotent]
final class GetAuditLogTool extends AbstractMcpTool
{
    public function handle(Request $request): Response
    {
        $input         = $request->toArray();
        $transactionId = array_key_exists('transaction_id', $input) ? (int) $input['transaction_id'] : 0;
        if ($transactionId <= 0) {
            return Response::error('transaction_id is required and must be a positive integer.');
        }

        /** @var User $user */
        $user  = auth()->user();

        /** @var null|TransactionGroup $group */
        $group = TransactionGroup::where('user_id', $user->id)->find($transactionId);
        if (null === $group) {
            return Response::error(sprintf('Transaction #%d not found.', $transactionId));
        }

        // Ownership is already enforced by the user-scoped group lookup above; the
        // audit repository queries purely by auditable type+id.
        $repository = app(ALERepositoryInterface::class);

        $entries = $repository->getForObject($group)
            ->map(fn(AuditLogEntry $entry): array => $this->mapEntry($entry, 'group', null));

        foreach ($group->transactionJournals()->get() as $journal) {
            $journalEntries = $repository->getForId(TransactionJournal::class, (int) $journal->id)
                ->map(fn(AuditLogEntry $entry): array => $this->mapEntry($entry, 'journal', (int) $journal->id));
            $entries        = $entries->concat($journalEntries);
        }

        $sorted = $entries->sortBy('created_at')->values()->all();

        return Response::json([
            'transaction_id' => $transactionId,
            'count'          => count($sorted),
            'entries'        => $sorted
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->integer()->required()->description('Numeric ID of the transaction whose change history you want.')
        ];
    }

    /**
     * Flatten one audit-log row into a compact, client-friendly shape.
     *
     * @return array<string, mixed>
     */
    private function mapEntry(AuditLogEntry $entry, string $scope, null|int $journalId): array
    {
        return [
            'id'           => (int) $entry->id,
            'scope'        => $scope,
            'journal_id'   => $journalId,
            'action'       => $entry->action,
            'before'       => $entry->before,
            'after'        => $entry->after,
            'changer_type' => class_basename($entry->changer_type),
            'changer_id'   => (int) $entry->changer_id,
            'created_at'   => $entry->created_at?->toAtomString()
        ];
    }
}
