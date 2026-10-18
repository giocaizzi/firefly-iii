<?php

/*
 * AttachmentsWriteTool.php
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

use FireflyIII\Mcp\Support\InvalidArgument;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Safe\Exceptions\UrlException;
use Throwable;

use function Safe\base64_decode;

#[Description('Attach a small file (base64) to an object, update attachment metadata, or delete an attachment.')]
#[IsDestructive]
final class AttachmentsWriteTool extends AbstractApiTool
{
    public const int MAX_BYTES = 512 * 1024;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action'         => $schema->string()->enum(['create', 'update', 'delete'])->required(),
            'id'             => $schema->integer()->description('For update/delete.'),
            'content_base64' => $schema->string()->description(sprintf('create: file content, max %d KiB.', self::MAX_BYTES / 1024)),
            ...$this->writeSchema(
                $schema,
                'filename, title, notes; create also attachable_type (TransactionJournal|Account|Bill|Budget|Category|PiggyBank|Tag), attachable_id (journal id for transactions).'
            )
        ];
    }

    protected function run(Request $request): Response
    {
        $action = $this->actionArg($request, ['create', 'update', 'delete']);
        if ('create' !== $action) {
            return $this->writeEntity($request, $action, 'attachments', 'attachment', AttachmentsReadTool::COMPACT);
        }

        $content = $this->contentArg($request);
        $created = $this->api('POST', 'attachments', [], $this->dataArg($request), 'data.');
        $id      = (string) ($created['data']['id'] ?? '');

        try {
            $this->api('POST', sprintf('attachments/%s/upload', $id), [], null, 'content_base64', $content);
        } catch (Throwable $e) {
            // no orphan metadata without a file
            $this->api('DELETE', sprintf('attachments/%s', $id));

            throw $e;
        }
        $this->audit(['action' => $action, 'object' => 'attachment', 'id' => $id, 'bytes' => strlen($content)]);

        return $this->respondApi($this->api('GET', sprintf('attachments/%s', $id)), $request, AttachmentsReadTool::COMPACT);
    }

    private function contentArg(Request $request): string
    {
        $raw = $request->get('content_base64');

        try {
            $decoded = is_string($raw) ? base64_decode($raw, true) : '';
        } catch (UrlException) {
            $decoded = '';
        }
        if ('' === $decoded) {
            InvalidArgument::fail('content_base64', 'content_base64 must be non-empty base64 file content.');
        }
        if (strlen($decoded) > self::MAX_BYTES) {
            InvalidArgument::fail('content_base64', sprintf('File is %d bytes; the limit is %d.', strlen($decoded), self::MAX_BYTES), [
                'max' => self::MAX_BYTES
            ]);
        }

        return $decoded;
    }
}
