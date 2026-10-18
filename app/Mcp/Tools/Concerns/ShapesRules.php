<?php

/*
 * ShapesRules.php
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

use FireflyIII\Mcp\Support\HtmlText;
use Laravel\Mcp\Request;

/**
 * Reduced rule / rule-group output with HTML entities decoded in the free-text fields.
 *
 * Rule::description is stored HTML-escaped (e() mutator), so the raw transformer value reads
 * "&amp;" and grows to "&amp;amp;" when a client writes it back. Non-verbose output decodes it;
 * verbose output stays the raw JSON:API document.
 */
trait ShapesRules
{
    /**
     * @param array<string, mixed> $jsonApiDocument
     *
     * @return array<string, mixed>
     */
    protected function shape(array $jsonApiDocument, Request $request): array
    {
        $reduced = parent::shape($jsonApiDocument, $request);
        $data    = $reduced['data'] ?? null;
        if (!is_array($data)) {
            return $reduced;
        }
        $decode          = static fn(array $row): array => HtmlText::decodeKeys($row, ['title', 'description', 'rule_group_title']);
        $reduced['data'] = array_is_list($data) ? array_map($decode, $data) : $decode($data);

        return $reduced;
    }
}
