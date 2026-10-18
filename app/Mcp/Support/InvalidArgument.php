<?php

/*
 * InvalidArgument.php
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

use Illuminate\Validation\ValidationException;

use function Safe\json_encode;

/**
 * Machine-readable argument rejection for MCP tools and resources.
 *
 * laravel/mcp turns a ValidationException thrown from handle() into an error response whose
 * text is the exception's messages joined by spaces. Throwing exactly one message that is a
 * JSON object keeps that text parseable: {"error":"invalid_argument","field":..,"message":..}.
 */
final class InvalidArgument
{
    /**
     * @param array<string, mixed> $extra additional machine-readable keys (e.g. `allowed`, `max`)
     *
     * @throws ValidationException
     */
    public static function fail(string $field, string $message, array $extra = []): never
    {
        $payload = ['error' => 'invalid_argument', 'field' => $field, 'message' => $message, ...$extra];

        throw ValidationException::withMessages([$field => [json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]]);
    }
}
