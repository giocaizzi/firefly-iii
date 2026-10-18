<?php

/*
 * GuardsNameCreation.php
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

use FireflyIII\Mcp\Support\CreatedEntities;
use FireflyIII\Models\Account;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

use function Safe\json_encode;

/**
 * `create_missing` for write tools that accept account, category or tag names.
 *
 * Firefly III creates unknown expense/revenue accounts, categories and tags when a write
 * names them. That stays the default; `create_missing=false` makes the write fail instead,
 * before anything is stored. What was created is reported via CreatedEntities.
 */
trait GuardsNameCreation
{
    /**
     * `meta` entry listing what the write created; empty when nothing was.
     *
     * @return array<string, mixed>
     */
    protected function createdMeta(CreatedEntities $mark): array
    {
        $created = $mark->since();

        return [] === $created ? [] : ['created' => $created];
    }

    protected function createMissingArg(Request $request): bool
    {
        return (bool) $request->get('create_missing', true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function createMissingSchema(JsonSchema $schema): array
    {
        return [
            'create_missing' => $schema
                ->boolean()
                ->description('Default true: unknown account/category/tag names are created and listed in meta.created. false = error instead.')
        ];
    }

    /**
     * Names in one transaction line that resolve to nothing and would be created by the write.
     *
     * Mirrors the factory lookups: accounts by exact name within the types the journal type
     * expects on that side, categories and tags by exact name. An `*_id` that resolves wins.
     *
     * @param array<string, mixed> $line
     *
     * @return array<string, list<string>>
     */
    protected function missingNames(User $user, string $journalType, array $line): array
    {
        /** @var AccountRepositoryInterface $accounts */
        $accounts = app(AccountRepositoryInterface::class);
        $accounts->setUser($user);
        $missing = [];

        foreach (['source', 'destination'] as $direction) {
            $name = trim((string) ($line[sprintf('%s_name', $direction)] ?? ''));
            if ('' === $name) {
                continue;
            }

            /** @var list<string> $types */
            $types = config(sprintf('firefly.expected_source_types.%s.%s', $direction, $journalType), []);
            $id    = (int) ($line[sprintf('%s_id', $direction)] ?? 0);
            $byId  = $id > 0 ? $accounts->find($id) : null;
            if ($byId instanceof Account && in_array($byId->accountType->type, $types, true)) {
                continue;
            }
            if (null === $accounts->findByName($name, $types)) {
                $missing['accounts'][] = $name;
            }
        }

        $categoryName = trim((string) ($line['category_name'] ?? ''));
        $categoryId   = (int) ($line['category_id'] ?? 0);
        $hasCategory  = $categoryId > 0 && null !== $user->categories()->find($categoryId);
        if ('' !== $categoryName && !$hasCategory && null === $user->categories()->where('name', $categoryName)->first()) {
            $missing['categories'][] = $categoryName;
        }

        foreach (is_array($line['tags'] ?? null) ? $line['tags'] : [] as $tag) {
            $tag = trim((string) $tag);
            if ('' !== $tag && null === $user->tags()->where('tag', $tag)->first()) {
                $missing['tags'][] = $tag;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, list<string>> $missing
     */
    protected function missingNamesError(array $missing): Response
    {
        return Response::error(json_encode([
            'error'   => 'missing_names',
            'message' => 'These names do not exist and create_missing=false; create them first or pass ids.',
            'missing' => $missing
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
