<?php

/*
 * TagsWriteTool.php
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
use FireflyIII\Models\Tag;
use FireflyIII\Repositories\Tag\TagRepositoryInterface;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Create, update (rename) or delete a tag, or merge one into another (moves its transactions, then deletes it).')]
#[IsDestructive]
final class TagsWriteTool extends AbstractApiTool
{
    private const array ACTIONS = ['create', 'update', 'delete', 'merge'];

    private const array COMPACT = ['id', 'tag', 'date', 'description'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(self::ACTIONS)->required(),
            'id'     => $schema->string()->description('Tag id or name (update/delete/merge source).'),
            'into'   => $schema->string()->description('merge: target tag id or name.'),
            ...$this->writeSchema($schema, 'tag, description, date.')
        ];
    }

    protected function run(Request $request): Response
    {
        $action = $this->actionArg($request, self::ACTIONS);
        if ('merge' !== $action) {
            return $this->writeEntity($request, $action, 'tags', 'tag', self::COMPACT, ['transactions' => 'transactions'], true);
        }

        /** @var User $user */
        $user       = auth()->user();
        $repository = app(TagRepositoryInterface::class);
        $repository->setUser($user);
        $from = $this->findTag($repository, rawurldecode($this->idArg($request, 'id', true)));
        $into = $this->findTag($repository, rawurldecode($this->idArg($request, 'into', true)));
        if ($from->id === $into->id) {
            InvalidArgument::fail('into', 'into must be a different tag.');
        }

        $count = count($repository->getJournalIds($from));
        DB::transaction(static function () use ($repository, $from, $into): void {
            $tagged = DB::table('tag_transaction_journal')->where('tag_id', $into->id)->pluck('transaction_journal_id')->all();
            DB::table('tag_transaction_journal')->where('tag_id', $from->id)->whereIn('transaction_journal_id', $tagged)->delete();
            DB::table('tag_transaction_journal')->where('tag_id', $from->id)->update(['tag_id' => $into->id]);
            $repository->destroy($from);
        });
        Preferences::mark();
        $this->audit(['action' => $action, 'object' => 'tag', 'id' => $from->id, 'into' => $into->id, 'transactions' => $count]);

        return Response::text(sprintf(
            'Merged tag "%s" into "%s" (#%d): %d transactions moved, "%s" deleted.',
            $from->tag,
            $into->tag,
            $into->id,
            $count,
            $from->tag
        ));
    }

    private function findTag(TagRepositoryInterface $repository, string $idOrName): Tag
    {
        $tag = ctype_digit($idOrName) ? $repository->find((int) $idOrName) : null;
        $tag ??= $repository->findByTag($idOrName);
        if (null === $tag) {
            throw new ApiFailure(sprintf('Tag "%s" not found.', $idOrName));
        }

        return $tag;
    }
}
