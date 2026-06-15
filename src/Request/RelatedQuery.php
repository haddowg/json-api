<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Request;

/**
 * The per-relationship sort + filter a client supplied for one relationship
 * (include) path through the Relationship Queries profile's `relatedQuery` /
 * `rQ` family, parsed from the primary request.
 *
 * A read-only carrier returned by
 * {@see JsonApiRequestInterface::getRelatedQuery()}. `$sort` is the raw sort
 * string in standard JSON:API grammar (comma-separated, `-` prefix = descending,
 * order-significant), or `null` when none was supplied; `$filter` is the raw
 * `filter[<key>]` map (the same shape as the primary `?filter`). The values are
 * raw client input — the host validates the sort/filter keys against the
 * addressed relationship's vocabulary (the related-collection endpoint's
 * vocabulary) and rejects an unknown key with a `400`, exactly as the endpoint
 * does.
 *
 * @see RelationshipQueriesProfile
 */
final readonly class RelatedQuery
{
    /**
     * @param array<string, mixed> $filter
     */
    public function __construct(
        public ?string $sort = null,
        public array $filter = [],
    ) {}

    /**
     * Whether this carries any sort or filter — `false` for the empty default a
     * path with no `relatedQuery` / `rQ` params resolves to.
     */
    public function isEmpty(): bool
    {
        return $this->sort === null && $this->filter === [];
    }

    /**
     * The requested sort as a list of field clauses (a `-` prefix marks
     * descending), split from the raw {@see $sort} string in the standard
     * JSON:API sort grammar; an empty list when no sort was supplied.
     *
     * @return list<string>
     */
    public function sortFields(): array
    {
        if ($this->sort === null || $this->sort === '') {
            return [];
        }

        return \array_values(\array_filter(
            \array_map('\trim', \explode(',', $this->sort)),
            static fn(string $field): bool => $field !== '',
        ));
    }

    /**
     * The **plain-form** query string for this relationship's own endpoint:
     * `sort=…` and `filter[…]=…` in the spec's plain grammar (not the profile's
     * `relatedQuery[…]` form, which only addresses a relationship from a parent
     * request). The host feeds it to a {@see \haddowg\JsonApi\Schema\Relationship\RelationshipPagination}
     * so the relationship-object pagination links mirror the client-supplied
     * sort/filter; the page strategy appends `page[…]`. Empty when this carries
     * neither sort nor filter.
     */
    public function toPlainQueryString(): string
    {
        $params = [];

        if ($this->sort !== null && $this->sort !== '') {
            $params['sort'] = $this->sort;
        }

        if ($this->filter !== []) {
            $params['filter'] = $this->filter;
        }

        return \http_build_query($params);
    }
}
