<?php

/*
 * CategoriesWriteTool.php
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
use FireflyIII\Models\Category;
use FireflyIII\Repositories\Category\CategoryRepositoryInterface;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Create or update (rename) a category, or merge one into another (moves its transactions, then deletes it).')]
#[IsDestructive]
final class CategoriesWriteTool extends AbstractApiTool
{
    private const array ACTIONS = ['create', 'update', 'merge'];

    private const array COMPACT = ['id', 'name', 'notes'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(self::ACTIONS)->required(),
            'id'     => $schema->integer()->description('update / merge source.'),
            'into'   => $schema->integer()->description('merge: target category id.'),
            ...$this->writeSchema($schema, 'name, notes.')
        ];
    }

    protected function run(Request $request): Response
    {
        $action = $this->actionArg($request, self::ACTIONS);
        if ('merge' !== $action) {
            return $this->writeEntity($request, $action, 'categories', 'category', self::COMPACT);
        }

        /** @var User $user */
        $user       = auth()->user();
        $repository = app(CategoryRepositoryInterface::class);
        $repository->setUser($user);
        $from = $this->findCategory($repository, (int) $this->idArg($request));
        $into = $this->findCategory($repository, (int) $this->idArg($request, 'into'));
        if ($from->id === $into->id) {
            InvalidArgument::fail('into', 'into must be a different category.');
        }

        // categories link to journals and, for split-level categories, to transactions
        $count = DB::table('category_transaction_journal')->where('category_id', $from->id)->count();
        DB::transaction(static function () use ($repository, $from, $into): void {
            foreach (['category_transaction_journal' => 'transaction_journal_id', 'category_transaction' => 'transaction_id'] as $table => $column) {
                $linked = DB::table($table)->where('category_id', $into->id)->pluck($column)->all();
                DB::table($table)->where('category_id', $from->id)->whereIn($column, $linked)->delete();
                DB::table($table)->where('category_id', $from->id)->update(['category_id' => $into->id]);
            }
            $repository->destroy($from);
        });
        Preferences::mark();
        $this->audit(['action' => $action, 'object' => 'category', 'id' => $from->id, 'into' => $into->id, 'transactions' => $count]);

        return Response::text(sprintf(
            'Merged category "%s" into "%s" (#%d): %d transactions moved, "%s" deleted.',
            $from->name,
            $into->name,
            $into->id,
            $count,
            $from->name
        ));
    }

    private function findCategory(CategoryRepositoryInterface $repository, int $id): Category
    {
        return $repository->find($id) ?? throw new ApiFailure(sprintf('Category #%d not found.', $id));
    }
}
