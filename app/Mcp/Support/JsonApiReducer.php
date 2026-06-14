<?php

/*
 * JsonApiReducer.php
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
 * Structural reducer that compacts a JSON:API document into a lean shape.
 *
 * Rules:
 *  - data (collection) -> array of flat objects.
 *  - data (single item) -> flat object.
 *  - each item: drop `type` and `links`; merge `id` (numeric cast) + flatten `attributes`;
 *    flatten `relationships.X.data.id` (single or array of ids) into `X_id` / `X_ids`.
 *  - preserve `meta.pagination` verbatim and any other top-level meta keys.
 *  - if `included` is present, recursively reduce into `_included` keyed by `"type/id"`.
 *  - when `include_nulls` is false (default) attributes whose value is null are omitted.
 */
final class JsonApiReducer
{
    /**
     * Reduce a JSON:API document.
     *
     * @param array<string, mixed> $document
     * @param array{include_nulls?: bool} $options
     *
     * @return array<string, mixed>
     */
    public function reduce(array $document, array $options = []): array
    {
        $includeNulls = (bool) ($options['include_nulls'] ?? false);

        $result = [];

        if (array_key_exists('data', $document)) {
            $result['data'] = $this->reduceData($document['data'], $includeNulls);
        }

        if (array_key_exists('meta', $document) && is_array($document['meta'])) {
            $result['meta'] = $document['meta'];
        }

        if (array_key_exists('included', $document) && is_array($document['included'])) {
            $included = [];
            /** @var list<array<string, mixed>> $items */
            $items = array_values($document['included']);
            foreach ($items as $item) {
                $key            = $this->includedKey($item);
                $included[$key] = $this->reduceItem($item, $includeNulls);
            }
            $result['_included'] = $included;
        }

        return $result;
    }

    /**
     * Cast an id to int when it's a numeric string; otherwise leave it as-is.
     */
    private function castId(mixed $id): mixed
    {
        if (is_int($id)) {
            return $id;
        }
        if (is_string($id) && ctype_digit($id)) {
            return (int) $id;
        }

        return $id;
    }

    /**
     * Build the "_included" key for a JSON:API included resource: "type/id".
     *
     * @param array<string, mixed> $item
     */
    private function includedKey(array $item): string
    {
        $type = array_key_exists('type', $item) ? (string) $item['type'] : 'unknown';
        $id   = array_key_exists('id', $item) ? (string) $this->castId($item['id']) : '';

        return $type . '/' . $id;
    }

    /**
     * Detect whether a JSON:API `data` value is a collection (list) vs a single object.
     */
    private function isCollection(mixed $data): bool
    {
        if (!is_array($data)) {
            return false;
        }
        if ([] === $data) {
            return true;
        }

        return array_is_list($data);
    }

    /**
     * Reduce the `data` member: either a collection, a single item, or a non-array (passed through).
     */
    private function reduceData(mixed $data, bool $includeNulls): mixed
    {
        if ($this->isCollection($data)) {
            /** @var list<array<string, mixed>> $data */
            $reduced = [];
            foreach ($data as $item) {
                $reduced[] = $this->reduceItem($item, $includeNulls);
            }

            return $reduced;
        }

        if (is_array($data)) {
            /** @var array<string, mixed> $data */
            return $this->reduceItem($data, $includeNulls);
        }

        return $data;
    }

    /**
     * Reduce a single JSON:API resource object to a flat associative array.
     *
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function reduceItem(array $item, bool $includeNulls): array
    {
        $flat = [];

        if (array_key_exists('id', $item)) {
            $flat['id'] = $this->castId($item['id']);
        }

        if (array_key_exists('attributes', $item) && is_array($item['attributes'])) {
            foreach ($item['attributes'] as $name => $value) {
                if (!$includeNulls && null === $value) {
                    continue;
                }
                $flat[(string) $name] = $value;
            }
        }

        if (array_key_exists('relationships', $item) && is_array($item['relationships'])) {
            foreach ($item['relationships'] as $name => $relationship) {
                if (!is_array($relationship) || !array_key_exists('data', $relationship)) {
                    continue;
                }
                $relData = $relationship['data'];

                if (null === $relData) {
                    if ($includeNulls) {
                        $flat[$name . '_id'] = null;
                    }

                    continue;
                }

                if ($this->isCollection($relData)) {
                    /** @var list<array<string, mixed>> $relData */
                    $ids = [];
                    foreach ($relData as $entry) {
                        if (array_key_exists('id', $entry)) {
                            $ids[] = $this->castId($entry['id']);
                        }
                    }
                    $flat[$name . '_ids'] = $ids;

                    continue;
                }

                if (is_array($relData) && array_key_exists('id', $relData)) {
                    $flat[$name . '_id'] = $this->castId($relData['id']);
                }
            }
        }

        return $flat;
    }
}
