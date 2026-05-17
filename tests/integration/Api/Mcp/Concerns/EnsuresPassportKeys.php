<?php

/*
 * EnsuresPassportKeys.php
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

namespace Tests\integration\Api\Mcp\Concerns;

use Illuminate\Support\Facades\Artisan;

/**
 * Ensures Passport's OAuth2 RSA key pair exists before the test boots the Passport guard.
 *
 * Background: tests that touch the `api` guard (Passport driver) instantiate a CryptKey
 * which fails with `LogicException: Invalid key supplied` when storage/oauth-*.key are
 * absent. Calling `php artisan passport:keys` is idempotent — it writes the keys only when
 * they are missing — so we invoke it via the in-process Artisan facade once per test.
 */
trait EnsuresPassportKeys
{
    protected function ensurePassportKeysExist(): void
    {
        $privateKey = storage_path('oauth-private.key');
        $publicKey  = storage_path('oauth-public.key');
        if (file_exists($privateKey) && file_exists($publicKey)) {
            return;
        }

        // Quiet to avoid noise in `failOnRisky` runs; the command is idempotent.
        Artisan::call('passport:keys', ['--force' => true]);
    }
}
