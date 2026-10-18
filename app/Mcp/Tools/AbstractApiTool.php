<?php

/*
 * AbstractApiTool.php
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

use FireflyIII\Mcp\Support\ApiBridge;
use FireflyIII\Mcp\Support\ApiFailure;
use FireflyIII\Mcp\Support\InvalidArgument;
use FireflyIII\Mcp\Support\LastChanged;
use FireflyIII\Mcp\Support\TransactionProjector;
use FireflyIII\Support\Facades\Amount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

use function Safe\json_decode;
use function Safe\preg_match;

/**
 * Base for the `<area>-read` / `<area>-write` tools, which run on Firefly III's own REST API.
 *
 * Every call goes through ApiBridge, so validation, ownership checks, repositories and
 * transformers are exactly the API's. Read tools return compact rows (a per-resource key
 * list), `fields` picks any attribute, `compact=false` keeps all of them and `verbose=true`
 * returns the raw JSON:API document. API validation errors surface in the shared
 * invalid_argument format; other API errors become MCP error responses.
 */
abstract class AbstractApiTool extends AbstractMcpTool
{
    /**
     * Compact scalars: "12.500000000000" -> "12.5"; a day-boundary timestamp ("2026-01-31T23:59:59+01:00") -> "2026-01-31".
     */
    private static function compactValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if (1 === preg_match('/^-?\d+\.\d+$/', $value)) {
            return TransactionProjector::trimAmount($value);
        }
        if (1 === preg_match('/^(\d{4}-\d{2}-\d{2})T(00:00:00|23:59:59)([+-]\d{2}:\d{2}|Z)?$/', $value, $matches)) {
            return $matches[1];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(SymfonyResponse $response): array
    {
        $content = (string) $response->getContent();
        if ('' === $content || !str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return [];
        }
        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->run($request);
        } catch (ApiFailure $e) {
            return Response::error($e->getMessage());
        }
    }

    abstract protected function run(Request $request): Response;

    /**
     * Required enum argument (case-insensitive).
     *
     * @param list<string> $allowed
     */
    protected function actionArg(Request $request, array $allowed, string $key = 'action'): string
    {
        if (null === $request->get($key)) {
            InvalidArgument::fail($key, sprintf('%s is required: one of %s.', $key, implode(', ', $allowed)), ['allowed' => $allowed]);
        }

        return $this->enumArg($request, $key, $allowed, $allowed[0]);
    }

    /**
     * Call the REST API and return the decoded JSON body ([] for an empty body).
     *
     * A 422 becomes an invalid_argument error on `$fieldPrefix . <api field>`; any other error status an ApiFailure.
     *
     * @param array<string, mixed>      $query
     * @param null|array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    protected function api(string $method, string $path, array $query = [], null|array $json = null, string $fieldPrefix = '', null|string $raw = null): array
    {
        $response = $this->apiResponse($method, $path, $query, $json, $fieldPrefix, $raw);

        return self::decode($response);
    }

    /**
     * Call the REST API and return the raw response body (e.g. a CSV export), with the same error handling as api().
     *
     * @param array<string, mixed> $query
     */
    protected function apiContent(string $method, string $path, array $query = []): string
    {
        return (string) $this->apiResponse($method, $path, $query)->getContent();
    }

    /**
     * Log a successful write to the audit channel, like every MCP write tool.
     *
     * @param array<string, mixed> $context
     */
    protected function audit(array $context): void
    {
        Log::channel('audit')->info('MCP write', ['tool' => $this->name(), 'user_id' => auth()->id(), ...$context]);
    }

    /**
     * Deleting something that still has dependants needs `confirm=true`.
     *
     * @param array<string, string> $dependants label => API list path whose total counts the dependants
     */
    protected function confirmDelete(Request $request, string $what, array $dependants): void
    {
        if (true === $request->get('confirm')) {
            return;
        }
        $found = [];
        foreach ($dependants as $label => $path) {
            $body  = $this->api('GET', $path, ['limit' => 1]);
            $total = (int) ($body['meta']['pagination']['total'] ?? count($body['data'] ?? []));
            if ($total > 0) {
                $found[$label] = $total;
            }
        }
        if ([] !== $found) {
            $list = implode(', ', array_map(static fn(string $label, int $count): string => sprintf('%d %s', $count, $label), array_keys($found), $found));
            InvalidArgument::fail('confirm', sprintf('%s still has %s; pass confirm=true to delete it anyway.', $what, $list), ['dependants' => $found]);
        }
    }

    /**
     * The `data` object of write tools (API body fields).
     *
     * @return array<string, mixed>
     */
    protected function dataArg(Request $request, bool $required = true): array
    {
        $raw = $request->get('data');
        if (null === $raw && !$required) {
            return [];
        }
        if (!is_array($raw) || [] !== $raw && array_is_list($raw)) {
            InvalidArgument::fail('data', 'data must be an object of API fields.', ['received' => $raw]);
        }

        /** @var array<string, mixed> $raw */
        return $raw;
    }

    /**
     * `fields`: list (or comma-separated string) of row keys to keep.
     *
     * @return null|list<string>
     */
    protected function fieldsArg(Request $request): null|array
    {
        $raw = $request->get('fields');
        if (null === $raw) {
            return null;
        }
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            InvalidArgument::fail('fields', 'fields must be a list of field names.', ['received' => $raw]);
        }
        $fields = [];
        foreach ($raw as $field) {
            if (!is_string($field) || '' === trim($field)) {
                InvalidArgument::fail('fields', 'fields must be a list of non-empty field names.', ['received' => $raw]);
            }
            $fields[] = trim($field);
        }

        return [] === $fields ? null : array_values(array_unique($fields));
    }

    /**
     * Required id argument: a positive integer, or (with $allowString) any non-empty string such as a currency code.
     */
    protected function idArg(Request $request, string $key = 'id', bool $allowString = false): string
    {
        $raw = $request->get($key);
        if (is_int($raw) && $raw > 0) {
            return (string) $raw;
        }
        if (is_string($raw) && '' !== trim($raw) && ($allowString || 1 === preg_match('/^\d+$/', trim($raw)))) {
            return rawurlencode(trim($raw));
        }

        InvalidArgument::fail($key, sprintf('%s is required here and must be %s.', $key, $allowString ? 'an id or code' : 'a positive integer'), [
            'received' => $raw
        ]);
    }

    /**
     * Query parameters for an API list call: page/limit plus the given optional date arguments.
     *
     * @param list<string> $dates
     *
     * @return array<string, mixed>
     */
    protected function listQuery(Request $request, array $dates = ['start', 'end']): array
    {
        [$page, $limit] = $this->pageAndLimit($request);
        $query = ['page' => $page, 'limit' => $limit];
        foreach ($dates as $key) {
            $query[$key] = $this->dateArg($request, $key)?->format('Y-m-d');
        }

        return $query;
    }

    /**
     * Schema entries shared by read tools.
     *
     * @return array<string, mixed>
     */
    protected function readSchema(JsonSchema $schema): array
    {
        return [
            ...$this->pagingSchema($schema),
            'fields' => $schema->array()->items($schema->string())->description('Row keys to keep.'),
            ...$this->compactSchema($schema),
            ...$this->outputFlagsSchema($schema)
        ];
    }

    /**
     * Shape an API document: raw when verbose, else reduced rows narrowed to `fields` or, when compact, to $compactKeys.
     *
     * @param array<string, mixed> $document
     * @param list<string>         $compactKeys
     */
    protected function respondApi(array $document, Request $request, array $compactKeys): Response
    {
        $fields = $this->fieldsArg($request);
        if ((bool) $request->get('verbose', false)) {
            return Response::json($document);
        }
        $reduced = $this->reducer()->reduce($document, ['include_nulls' => $this->includeNulls($request)]);
        $keys    = $fields ?? ((bool) $request->get('compact', true) ? $compactKeys : null);
        $data    = $reduced['data'] ?? null;
        if (null !== $keys && is_array($data)) {
            $map             = array_combine($keys, $keys);
            $narrow          = fn(array $row): array => array_map(self::compactValue(...), $this->pick($row, $map, $this->includeNulls($request)));
            $reduced['data'] = array_is_list($data) ? array_map($narrow, $data) : $narrow($data);
            unset($reduced['_included']);
        }

        return Response::json($this->withPagingHints($reduced));
    }

    /**
     * Transaction-group documents use the shared compact transaction rows.
     *
     * @param array<string, mixed> $document
     */
    protected function respondTransactions(array $document, Request $request): Response
    {
        if ((bool) $request->get('verbose', false)) {
            return Response::json($document);
        }
        $document = LastChanged::apply($document);
        if (!(bool) $request->get('compact', true)) {
            return Response::json(self::fieldsIgnoredWarning(
                $this->withPagingHints($this->reducer()->reduce($document, ['include_nulls' => $this->includeNulls($request)])),
                $request
            ));
        }
        $projected = new TransactionProjector()->project(
            $document,
            Amount::getPrimaryCurrency()->code,
            $this->fieldsArg($request),
            TransactionProjector::DEFAULT_DESCRIPTION_MAX,
            $this->includeNulls($request)
        );

        return Response::json($this->withPagingHints($projected));
    }

    /**
     * Create (POST $collection), update (PUT $collection/{id}) or delete (DELETE $collection/{id}) one API object.
     *
     * Deletes ask for `confirm=true` while any of the $dependants (label => sub-path of the object) is non-empty.
     * $data replaces the `data` argument when the tool has to complete it first.
     *
     * @param list<string>              $compact
     * @param array<string, string>     $dependants
     * @param null|array<string, mixed> $data
     */
    protected function writeEntity(
        Request $request,
        string $action,
        string $collection,
        string $label,
        array $compact,
        array $dependants = [],
        bool $stringId = false,
        null|array $data = null
    ): Response {
        if ('create' === $action) {
            $document = $this->api('POST', $collection, [], $data ?? $this->dataArg($request), 'data.');
            $this->audit(['action' => $action, 'object' => $label, 'id' => $document['data']['id'] ?? null]);

            return $this->respondApi($document, $request, $compact);
        }
        $id   = $this->idArg($request, 'id', $stringId);
        $path = sprintf('%s/%s', $collection, $id);
        if ('update' === $action) {
            $document = $this->api('PUT', $path, [], $data ?? $this->dataArg($request), 'data.');
            $this->audit(['action' => $action, 'object' => $label, 'id' => $id]);

            return $this->respondApi($document, $request, $compact);
        }
        $this->confirmDelete(
            $request,
            sprintf('%s %s', ucfirst($label), rawurldecode($id)),
            array_map(static fn(string $sub): string => sprintf('%s/%s', $path, $sub), $dependants)
        );
        $this->api('DELETE', $path);
        $this->audit(['action' => $action, 'object' => $label, 'id' => $id]);

        return Response::json(['deleted' => $label, 'id' => ctype_digit($id) ? (int) $id : rawurldecode($id)]);
    }

    /**
     * Merge $source into $into: with `confirm=true` run $write (it moves the transactions and deletes
     * $source), otherwise return a preview and write nothing.
     *
     * @param array{id: int, name: string} $source
     * @param array{id: int, name: string} $into
     * @param callable(): void             $write
     */
    protected function mergeEntity(Request $request, string $label, array $source, array $into, int $moved, callable $write): Response
    {
        if (true !== $request->get('confirm')) {
            return Response::json([
                'preview'    => true,
                'would_move' => $moved,
                'source'     => $source,
                'into'       => $into,
                'message'    => sprintf('Nothing changed; pass confirm=true to move the transactions and delete %s "%s".', $label, $source['name'])
            ]);
        }
        $write();
        $this->audit(['action' => 'merge', 'object' => $label, 'id' => $source['id'], 'into' => $into['id'], 'transactions' => $moved]);

        return Response::json(['merged' => $label, 'id' => $source['id'], 'into' => $into['id'], 'moved' => $moved]);
    }

    /**
     * Schema entries shared by write tools.
     *
     * @return array<string, mixed>
     */
    protected function writeSchema(JsonSchema $schema, string $dataFields): array
    {
        return [
            'data'    => $schema->object()->description(sprintf('API fields: %s', $dataFields)),
            'confirm' => $schema->boolean()->description('Confirm a merge, or deleting with dependants.'),
            ...$this->compactSchema($schema),
            ...$this->outputFlagsSchema($schema)
        ];
    }

    /**
     * @param array<string, mixed>      $query
     * @param null|array<string, mixed> $json
     */
    private function apiResponse(
        string $method,
        string $path,
        array $query = [],
        null|array $json = null,
        string $fieldPrefix = '',
        null|string $raw = null
    ): SymfonyResponse {
        $response = app(ApiBridge::class)->send($method, $path, $query, $json, $raw);
        $status   = $response->getStatusCode();
        if (422 === $status) {
            $body = self::decode($response);

            /** @var array<string, list<string>> $errors */
            $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];
            $field  = (string) (array_key_first($errors) ?? '');
            $first  = $errors[$field][0] ?? (string) ($body['message'] ?? 'The API rejected the request.');

            InvalidArgument::fail('' === $field ? rtrim($fieldPrefix, '.') : $fieldPrefix . $field, $first, ['errors' => $errors]);
        }
        if ($status >= 400) {
            $message = (string) (self::decode($response)['message'] ?? $response->getContent());

            throw new ApiFailure(sprintf('%s %s failed (%d): %s', $method, $path, $status, '' === $message ? 'no details' : $message));
        }

        return $response;
    }
}
