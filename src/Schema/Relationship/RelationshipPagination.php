<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Relationship;

use haddowg\JsonApi\Pagination\PageInterface;
use haddowg\JsonApi\Schema\Link\Link;

/**
 * The pagination state a rendered to-many relationship carries so its
 * relationship object emits `first` / `prev` / `next` (and `last` when the
 * relation is countable) links — required by the spec to live in the
 * relationship's own `links` object, not the document.
 *
 * Built by the host (which owns the data layer and the paginator) for a
 * relationship rendered as `?include` linkage or links-only linkage. The page is
 * page 1 of the relationship's set, ordered/filtered by the Relationship Queries
 * profile's per-relationship sort/filter; `$queryString` is the **plain-form**
 * translation of those params (`sort=…&filter[…]=…`), so the emitted links use
 * the spec's plain `sort` / `filter` / `page` grammar against the relationship's
 * own endpoint — never the profile's `relatedQuery[…]` form (which only addresses
 * a relationship from a parent request).
 *
 * The link URLs are completed in {@see AbstractRelationship::transform()}, the
 * only layer that knows the parent resource's type + id and the base URI: the
 * carried page's {@see PageInterface::linkSet()} is invoked there against the
 * relationship-linkage endpoint URI.
 */
final readonly class RelationshipPagination
{
    /**
     * Page 1 of the relationship's ordered/filtered set.
     *
     * @var PageInterface<mixed>
     */
    public PageInterface $page;

    /**
     * @template T
     *
     * @param PageInterface<T> $page        page 1 of the relationship's ordered/filtered set
     * @param string           $queryString the plain-form (`sort` / `filter`) query string for the endpoint links
     */
    public function __construct(
        PageInterface $page,
        public string $queryString = '',
    ) {
        $this->page = $page;
    }

    /**
     * The relationship-object pagination links for `$endpointUri` (the
     * relationship-linkage endpoint), built from the page's link set against the
     * plain-form {@see $queryString}; `null` relations are dropped by the caller.
     *
     * @return array<string, Link|null>
     */
    public function linksFor(string $endpointUri): array
    {
        return $this->page->linkSet($endpointUri, $this->queryString);
    }
}
