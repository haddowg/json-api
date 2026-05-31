<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Operation;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * The parsed JSON:API query-parameter groups for an operation, decoupled from
 * the request object so a handler can be driven without a PSR-7 message.
 *
 * Each member is the spec-shaped projection of one query-param family:
 * - `fields` — sparse fieldsets, keyed by resource type → its requested fields;
 * - `includes` — the `include` paths (a flat list of relationship paths);
 * - `sort` — the `sort` fields (with any leading `-` preserved);
 * - `filter` — the `filter` map verbatim;
 * - `pagination` — the `page` map verbatim.
 *
 * This is a leaf value object: the readonly property is the accessor — no getters.
 */
final readonly class QueryParameters
{
    /**
     * @param array<string, list<string>> $fields
     * @param list<string>                 $includes
     * @param list<string>                 $sort
     * @param array<string, mixed>         $filter
     * @param array<string, mixed>         $pagination
     */
    public function __construct(
        public array $fields,
        public array $includes,
        public array $sort,
        public array $filter,
        public array $pagination,
    ) {}

    /**
     * Builds the parameters from a request.
     *
     * `sort`, `filter` and `pagination` come straight from the request's own
     * parsers ({@see JsonApiRequestInterface::getSorting()},
     * {@see JsonApiRequestInterface::getFiltering()},
     * {@see JsonApiRequestInterface::getPagination()}). `include` and `fields`
     * are read raw via {@see JsonApiRequestInterface::getQueryParam()} and parsed
     * into the spec shape here: a comma-separated `include` string becomes a
     * `list<string>` of paths, and each `fields[type]` comma-separated string
     * becomes a `list<string>` of field names keyed by type. Malformed values are
     * tolerated (skipped) rather than thrown — header/well-formedness validation
     * is the negotiation layer's job, not this projection's.
     */
    public static function fromRequest(JsonApiRequestInterface $request): self
    {
        return new self(
            self::parseFields($request),
            self::parseIncludes($request),
            $request->getSorting(),
            $request->getFiltering(),
            $request->getPagination(),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private static function parseFields(JsonApiRequestInterface $request): array
    {
        $raw = $request->getQueryParam('fields', []);
        if (\is_array($raw) === false) {
            return [];
        }

        $fields = [];
        foreach ($raw as $type => $value) {
            if (\is_string($value) === false) {
                continue;
            }

            $names = \array_values(\array_filter(
                \array_map('\trim', \explode(',', $value)),
                static fn(string $name): bool => $name !== '',
            ));

            $fields[(string) $type] = $names;
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    private static function parseIncludes(JsonApiRequestInterface $request): array
    {
        $raw = $request->getQueryParam('include', '');
        if (\is_string($raw) === false || $raw === '') {
            return [];
        }

        return \array_values(\array_filter(
            \array_map('\trim', \explode(',', $raw)),
            static fn(string $path): bool => $path !== '',
        ));
    }
}
