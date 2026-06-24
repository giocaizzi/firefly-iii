<?php

/*
 * DeleteCategoryTool.php
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

use FireflyIII\Repositories\Category\CategoryRepositoryInterface;
use FireflyIII\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Throwable;

/**
 * Delete a category by its id.
 *
 * Transactions that referenced the category are kept; they simply lose the category
 * association (the repository removes the category/journal links).
 */
#[Description('Delete a category by its id. Transactions keep existing but lose their link to this category.')]
#[IsDestructive]
final class DeleteCategoryTool extends AbstractMcpTool
{
    public function handle(Request $request): Response
    {
        $input      = $request->toArray();
        $categoryId = array_key_exists('category_id', $input) ? (int) $input['category_id'] : 0;
        if ($categoryId <= 0) {
            return Response::error('category_id is required and must be a positive integer.');
        }

        /** @var User $user */
        $user       = auth()->user();
        $repository = app(CategoryRepositoryInterface::class);
        $repository->setUser($user);

        $category = $repository->find($categoryId);
        if (null === $category) {
            return Response::error(sprintf('Category #%d not found.', $categoryId));
        }

        try {
            $repository->destroy($category);
        } catch (Throwable $e) {
            Log::channel('audit')->error('MCP write failed', [
                'tool'      => $this->name(),
                'user_id'   => auth()->id(),
                'stage'     => 'repository',
                'exception' => $e->getMessage()
            ]);

            return Response::error(sprintf('Failed to delete category #%d: %s', $categoryId, $e->getMessage()));
        }

        Log::channel('audit')->info('MCP write', [
            'tool'        => $this->name(),
            'user_id'     => auth()->id(),
            'category_id' => $categoryId
        ]);

        return Response::text(sprintf('Deleted category #%d.', $categoryId));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category_id' => $schema->integer()->required()->description('Numeric ID of the category to delete.')
        ];
    }
}
