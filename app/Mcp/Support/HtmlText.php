<?php

/*
 * HtmlText.php
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

/**
 * Undo HTML escaping on text the models store escaped (e.g. Rule::description runs e() on set).
 *
 * MCP clients are not browsers: they should see "Food & drinks", not "Food &amp;amp; drinks".
 * Decoding repeats until stable so values escaped twice (an escaped value written back) also
 * come out clean.
 */
final class HtmlText
{
    private const int MAX_PASSES = 5;

    public static function decode(string $text): string
    {
        for ($i = 0; $i < self::MAX_PASSES; ++$i) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = $decoded;
        }

        return $text;
    }

    /**
     * Decode the given string keys of a flat row in place.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $keys
     *
     * @return array<string, mixed>
     */
    public static function decodeKeys(array $row, array $keys): array
    {
        foreach ($keys as $key) {
            if (is_string($row[$key] ?? null)) {
                $row[$key] = self::decode($row[$key]);
            }
        }

        return $row;
    }
}
