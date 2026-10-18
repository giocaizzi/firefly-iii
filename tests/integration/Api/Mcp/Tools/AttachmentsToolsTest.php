<?php

/*
 * AttachmentsToolsTest.php
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

namespace Tests\integration\Api\Mcp\Tools;

use FireflyIII\Mcp\Tools\AttachmentsReadTool;
use FireflyIII\Mcp\Tools\AttachmentsWriteTool;
use FireflyIII\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Tests\integration\Api\Mcp\Concerns\CallsApiTools;
use Tests\integration\Api\Mcp\Concerns\EnsuresPassportKeys;
use Tests\integration\Api\Mcp\Concerns\SeedsFireflyData;
use Tests\integration\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class AttachmentsToolsTest extends TestCase
{
    use CallsApiTools;
    use EnsuresPassportKeys;
    use SeedsFireflyData;

    /**
     * @covers \FireflyIII\Mcp\Tools\AttachmentsReadTool
     * @covers \FireflyIII\Mcp\Tools\AttachmentsWriteTool
     */
    public function testAttachmentRoundTrip(): void
    {
        $user = $this->createAuthenticatedUser();
        $bill = $this->createBill($user, 'Insurance');

        $created = $this->okJson($user, AttachmentsWriteTool::class, [
            'action'         => 'create',
            'content_base64' => base64_encode("policy text\n"),
            'data'           => ['filename' => 'policy.txt', 'attachable_type' => 'Bill', 'attachable_id' => $bill->id, 'title' => 'Policy']
        ]);
        $id = $created['data']['id'];
        self::assertSame(
            ['attachable_type' => 'Bill', 'attachable_id' => (string) $bill->id, 'filename' => 'policy.txt', 'title' => 'Policy'],
            array_intersect_key($created['data'], array_flip(['attachable_type', 'attachable_id', 'filename', 'title']))
        );
        self::assertSame(12, (int) $created['data']['size']);
        Storage::disk('upload')->assertExists(sprintf('at-%d.data', $id));

        $list = $this->okJson($user, AttachmentsReadTool::class, ['action' => 'list']);
        self::assertSame([$id], array_column($list['data'], 'id'));

        $updated = $this->okJson($user, AttachmentsWriteTool::class, ['action' => 'update', 'id' => $id, 'data' => ['title' => 'Car policy']]);
        self::assertSame('Car policy', $updated['data']['title']);
        self::assertSame('Car policy', $this->okJson($user, AttachmentsReadTool::class, ['action' => 'get', 'id' => $id])['data']['title']);

        $this->invokeTool($user, AttachmentsWriteTool::class, ['action' => 'delete', 'id' => $id])->assertHasNoErrors();
        self::assertFalse(Attachment::whereKey($id)->exists());
    }

    /**
     * @covers \FireflyIII\Mcp\Tools\AttachmentsWriteTool
     */
    public function testRejectsBadContentAndLeavesNoOrphans(): void
    {
        $user = $this->createAuthenticatedUser();
        $bill = $this->createBill($user, 'Insurance');
        $data = ['filename' => 'a.txt', 'attachable_type' => 'Bill', 'attachable_id' => $bill->id];

        $this->invalidArgument($user, AttachmentsWriteTool::class, ['action' => 'create', 'content_base64' => '%%%', 'data' => $data], 'content_base64');
        $tooBig = base64_encode(str_repeat('x', AttachmentsWriteTool::MAX_BYTES + 1));
        $this->invalidArgument($user, AttachmentsWriteTool::class, ['action' => 'create', 'content_base64' => $tooBig, 'data' => $data], 'content_base64');
        $this->invalidArgument(
            $user,
            AttachmentsWriteTool::class,
            ['action' => 'create', 'content_base64' => base64_encode('x'), 'data' => [...$data, 'attachable_type' => 'User']],
            'data.attachable_type'
        );
        self::assertSame(0, Attachment::count());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePassportKeysExist();
        Storage::fake('upload');
    }
}
