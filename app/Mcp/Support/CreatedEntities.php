<?php

/*
 * CreatedEntities.php
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

namespace FireflyIII\Mcp\Support;

use FireflyIII\Models\Account;
use FireflyIII\Models\Category;
use FireflyIII\Models\Tag;
use FireflyIII\User;

/**
 * Reports the accounts, categories and tags a write created as a side effect of name resolution.
 *
 * Snapshot the user's highest ids before the write with mark(), then since() lists every row
 * created after it. Only non-empty lists are returned, so an empty array means nothing was created.
 */
final class CreatedEntities
{
    /**
     * @param array{accounts: int, categories: int, tags: int} $marks
     */
    private function __construct(
        private readonly User $user,
        private readonly array $marks
    ) {}

    public static function mark(User $user): self
    {
        return new self($user, [
            'accounts'   => (int) Account::where('user_id', $user->id)->max('id'),
            'categories' => (int) Category::where('user_id', $user->id)->max('id'),
            'tags'       => (int) Tag::where('user_id', $user->id)->max('id')
        ]);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function since(): array
    {
        $accounts = Account::where('user_id', $this->user->id)
            ->where('id', '>', $this->marks['accounts'])
            ->with('accountType')
            ->orderBy('id')
            ->get()
            ->map(static fn(Account $account): array => ['id' => $account->id, 'name' => $account->name, 'type' => $account->accountType->type])
            ->all();
        $categories = Category::where('user_id', $this->user->id)
            ->where('id', '>', $this->marks['categories'])
            ->orderBy('id')
            ->get()
            ->map(static fn(Category $category): array => ['id' => $category->id, 'name' => $category->name])
            ->all();
        $tags = Tag::where('user_id', $this->user->id)
            ->where('id', '>', $this->marks['tags'])
            ->orderBy('id')
            ->get()
            ->map(static fn(Tag $tag): array => ['id' => $tag->id, 'tag' => $tag->tag])
            ->all();

        return array_filter(['accounts' => $accounts, 'categories' => $categories, 'tags' => $tags], static fn(array $rows): bool => [] !== $rows);
    }
}
