<?php

/*
 * AbstractMcpTool.php
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

use Carbon\Carbon;
use FireflyIII\Mcp\Support\InvalidArgument;
use FireflyIII\Mcp\Support\JsonApiReducer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use League\Fractal\Manager;
use League\Fractal\Serializer\JsonApiSerializer;

use function Safe\preg_match;

/**
 * Shared base for all Firefly III MCP tools.
 *
 * Provides the canonical JSON:API -> reducer -> structured-response pipeline plus the
 * shared `verbose` / `include_nulls` / `page` / `limit` argument handling and strict argument
 * validation (rejections are machine-readable, see InvalidArgument).
 */
abstract class AbstractMcpTool extends Tool
{
    public const int DEFAULT_LIMIT = 25;

    public const int MAX_LIMIT = 200;

    private const int MAX_PAGE = 2 ** 16;

    /**
     * Tool annotations as advertised over `tools/list`.
     *
     * Every tool states `readOnlyHint` explicitly so clients can split read-only tools from
     * write tools without falling back to the spec defaults (readOnlyHint=false,
     * destructiveHint=true). Read-only tools opt in with #[IsReadOnly]; write tools declare
     * #[IsDestructive] or #[IsDestructive(false)].
     *
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        return ['title' => $this->title(), 'readOnlyHint' => false, ...parent::annotations()];
    }

    /**
     * Human-readable title, without the "Tool" suffix every class name carries.
     */
    public function title(): string
    {
        return Str::chopEnd(parent::title(), ' Tool');
    }

    /**
     * Schema entry for the `compact` flag of tools with a compact default row.
     *
     * @return array<string, mixed>
     */
    protected function compactSchema(JsonSchema $schema): array
    {
        return ['compact' => $schema->boolean()->description('false = all attributes.')];
    }

    /**
     * Parse an optional date argument (YYYY-MM-DD or ISO 8601 date-time); rejects anything else.
     */
    protected function dateArg(Request $request, string $key): null|Carbon
    {
        $raw = $request->get($key);
        if (null === $raw || is_string($raw) && '' === trim($raw)) {
            return null;
        }
        $pattern = '/^(\d{4})-(\d{2})-(\d{2})([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/';
        if (!is_string($raw) || 1 !== preg_match($pattern, trim($raw), $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            InvalidArgument::fail($key, sprintf('%s must be a date in YYYY-MM-DD (or ISO 8601) format.', $key), ['received' => $raw]);
        }

        return Carbon::parse(trim($raw), config('app.timezone'));
    }

    /**
     * Resolve an enum-like string argument; unknown values are rejected with the allowed list.
     *
     * @param list<string> $allowed
     */
    protected function enumArg(Request $request, string $key, array $allowed, string $default): string
    {
        $raw = $request->get($key);
        if (null === $raw) {
            return $default;
        }
        $value = is_string($raw) ? strtolower(trim($raw)) : '';
        if (!in_array($value, $allowed, true)) {
            InvalidArgument::fail($key, sprintf('%s must be one of: %s.', $key, implode(', ', $allowed)), ['allowed' => $allowed, 'received' => $raw]);
        }

        return $value;
    }

    /**
     * Shared `include_nulls` flag.
     */
    protected function includeNulls(Request $request): bool
    {
        return (bool) $request->get('include_nulls', false);
    }

    /**
     * Fractal Manager configured to match the project's REST API (see Controller::getManager()).
     */
    protected function jsonApiManager(null|string $baseUrl = null): Manager
    {
        $manager = new Manager();
        $url     = $baseUrl ?? sprintf('%s/api/v1', request()->getSchemeAndHttpHost());
        $manager->setSerializer(new JsonApiSerializer($url));

        return $manager;
    }

    /**
     * Schema entries for the output flags every JSON:API tool accepts.
     *
     * @return array<string, mixed>
     */
    protected function outputFlagsSchema(JsonSchema $schema): array
    {
        return [
            'verbose'       => $schema->boolean()->description('Raw JSON:API.'),
            'include_nulls' => $schema->boolean()->description('Keep nulls/empties.')
        ];
    }

    /**
     * Resolve `page` (default 1) and `limit` (default 25, max 200); out-of-range values are rejected, never coerced.
     *
     * @return array{0: int, 1: int}
     */
    protected function pageAndLimit(Request $request): array
    {
        $page  = $this->positiveIntArg($request, 'page', 1);
        $limit = $this->positiveIntArg($request, 'limit', self::DEFAULT_LIMIT);
        if ($page > self::MAX_PAGE) {
            InvalidArgument::fail('page', sprintf('page must be at most %d.', self::MAX_PAGE), ['max' => self::MAX_PAGE]);
        }
        if ($limit > self::MAX_LIMIT) {
            InvalidArgument::fail('limit', sprintf('limit must be at most %d.', self::MAX_LIMIT), ['max' => self::MAX_LIMIT]);
        }

        return [$page, $limit];
    }

    /**
     * Schema entries for `page` / `limit`.
     *
     * @return array<string, mixed>
     */
    protected function pagingSchema(JsonSchema $schema): array
    {
        return [
            'page'  => $schema->integer()->description('From 1.'),
            'limit' => $schema->integer()->description(sprintf('Max %d, default %d.', self::MAX_LIMIT, self::DEFAULT_LIMIT))
        ];
    }

    /**
     * Project a reduced row onto a compact one: output key => source key. Missing/empty values are dropped unless include_nulls.
     *
     * @param array<string, mixed>  $row
     * @param array<string, string> $map
     *
     * @return array<string, mixed>
     */
    protected function pick(array $row, array $map, bool $includeNulls): array
    {
        $result = [];
        foreach ($map as $key => $source) {
            $value = $row[$source] ?? null;
            if (!$includeNulls && (null === $value || '' === $value || [] === $value)) {
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * A positive integer argument (ints or digit strings); anything else is rejected.
     */
    protected function positiveIntArg(Request $request, string $key, int $default): int
    {
        $raw = $request->get($key);
        if (null === $raw) {
            return $default;
        }
        if (is_string($raw) && 1 === preg_match('/^-?\d+$/', trim($raw))) {
            $raw = (int) trim($raw);
        }
        if (!is_int($raw)) {
            InvalidArgument::fail($key, sprintf('%s must be an integer.', $key), ['received' => $raw]);
        }
        if ($raw < 1) {
            InvalidArgument::fail($key, sprintf('%s must be at least 1.', $key), ['min' => 1, 'received' => $raw]);
        }

        return $raw;
    }

    /** Default reducer; subclasses override to compact their domain shape. */
    protected function reducer(): JsonApiReducer
    {
        return new JsonApiReducer();
    }

    /**
     * Apply the tool's output shape (unless `verbose=true`) and emit a Response carrying the JSON document.
     *
     * Uses Response::json() so the text content is the JSON payload. Tools that also declare
     * an outputSchema may wrap this Response with Response::structured() in their own handle().
     * Paginated shapes gain `meta.has_more` and `meta.next_page`; verbose output is untouched.
     * `$extraMeta` (e.g. a write's `created`) is merged into `meta` in every mode.
     *
     * @param array<string, mixed> $jsonApiDocument
     * @param array<string, mixed> $extraMeta
     */
    protected function respondJsonApi(array $jsonApiDocument, Request $request, array $extraMeta = []): Response
    {
        $verbose = (bool) $request->get('verbose', false);
        $result  = $verbose ? $jsonApiDocument : $this->withPagingHints($this->shape($jsonApiDocument, $request));
        if ([] !== $extraMeta) {
            $result['meta'] = [...(is_array($result['meta'] ?? null) ? $result['meta'] : []), ...$extraMeta];
        }

        return Response::json($result);
    }

    /**
     * Transaction rows take `fields` only in compact mode; say so instead of dropping it silently.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    protected static function fieldsIgnoredWarning(array $result, Request $request): array
    {
        if (null === $request->get('fields')) {
            return $result;
        }
        $meta             = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        $warnings         = is_array($meta['warnings'] ?? null) ? $meta['warnings'] : [];
        $warnings[]       = 'fields applies to compact rows only and was ignored with compact=false';
        $meta['warnings'] = $warnings;
        $result['meta']   = $meta;

        return $result;
    }

    /**
     * Non-verbose output shape. Default: the generic reducer; tools with a compact projection override this.
     *
     * @param array<string, mixed> $jsonApiDocument
     *
     * @return array<string, mixed>
     */
    protected function shape(array $jsonApiDocument, Request $request): array
    {
        return $this->reducer()->reduce($jsonApiDocument, ['include_nulls' => $this->includeNulls($request)]);
    }

    /**
     * Add `has_more` / `next_page` next to a Fractal `meta.pagination` block, and a
     * `meta.warnings` entry when `page` is past the last page (an empty list has one page).
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    protected function withPagingHints(array $result): array
    {
        $pagination = $result['meta']['pagination'] ?? null;
        if (!is_array($pagination) || !array_key_exists('current_page', $pagination) || !array_key_exists('total_pages', $pagination)) {
            return $result;
        }
        $current                    = (int) $pagination['current_page'];
        $hasMore                    = $current < (int) $pagination['total_pages'];
        $result['meta']['has_more'] = $hasMore;
        if ($hasMore) {
            $result['meta']['next_page'] = $current + 1;
        }
        $last                       = max(1, (int) $pagination['total_pages']);
        if ($current > $last) {
            $warnings                   = is_array($result['meta']['warnings'] ?? null) ? $result['meta']['warnings'] : [];
            $warnings[]                 = sprintf('page %d is past the last page (%d)', $current, $last);
            $result['meta']['warnings'] = $warnings;
        }

        return $result;
    }
}
