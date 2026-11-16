<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Relationship;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PageInterface;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Request\RelatedQuery;
use haddowg\JsonApi\Schema\Link\Link;

/**
 * The pagination state a rendered to-many relationship carries so its
 * relationship object emits `first` / `prev` / `next` (and `last` when the page
 * is counted) links, which the spec places in the relationship's own `links`
 * object, not the document's.
 *
 * Built by the host, which owns the data layer and the paginator, for every
 * to-many whose linkage `data` renders: included, or declared `withData()`. The
 * page is the first page of the relationship's members under its effective
 * paginator, in the order its related endpoint serves them, narrowed and ordered
 * by any Relationship Queries `relatedQuery` the request carries. `$queryString`
 * is the **plain-form** translation of that query (`sort=…&filter[…]=…`), so the
 * links use the spec's plain `sort` / `filter` / `page` grammar against the
 * relationship's own endpoint, never the profile's `relatedQuery[…]` form.
 *
 * `$total` is the member count when the page was counted. Core renders it as a
 * countable relation's `meta.total` unless a count seam already supplied one.
 *
 * The link URLs are completed in {@see AbstractRelationship::transform()}, the
 * only layer that knows the parent resource's type + id and the base URI: the
 * carried page's {@see PageInterface::linkSet()} is invoked there against the
 * relationship endpoint, or the related endpoint when only that one is exposed.
 */
final readonly class RelationshipPagination
{
    /**
     * The first page of the relationship's members.
     *
     * @var PageInterface<mixed>
     */
    public PageInterface $page;

    /**
     * @template T
     *
     * @param PageInterface<T> $page        the first page of the relationship's members
     * @param string           $queryString the plain-form (`sort` / `filter`) query string for the endpoint links
     * @param int|null         $total       the member count when the page was counted, else `null`
     */
    public function __construct(
        PageInterface $page,
        public string $queryString = '',
        public ?int $total = null,
    ) {
        $this->page = $page;
    }

    /**
     * The first page a host's windowed read of a relationship produced, rendered the
     * way the related endpoint renders the same read: a cursor result through the
     * cursor paginator's boundaries, a counted result as a count-based page with its
     * total, and anything else as a count-free page whose `next` follows
     * `$result->hasMore`.
     *
     * `$pageRequest` is the request the window was read from
     * ({@see firstPageRequest()}). `$counted` says whether the read was asked to count;
     * a total the result carries is used only then. `$from` and `$to` are the wire ids
     * of the first and last member, which a cursor page reports in its `meta.page`.
     *
     * @template T
     *
     * @param CollectionResult<object> $result
     * @param list<T>                  $items  the page's members, as rendered
     */
    public static function firstPage(
        PaginatorInterface $paginator,
        JsonApiRequestInterface $pageRequest,
        array $items,
        CollectionResult $result,
        bool $counted,
        string $queryString = '',
        int|string|null $from = null,
        int|string|null $to = null,
    ): self {
        if ($result instanceof CursorCollectionResult && $paginator instanceof CursorPaginator) {
            return new self(
                $paginator->fromBoundaries(
                    $pageRequest,
                    $items,
                    $result->cursorBefore ?? '',
                    $result->cursorAfter ?? '',
                    $result->hasMore,
                    $result->hasPrevious,
                    from: $from,
                    to: $to,
                ),
                $queryString,
                $counted ? $result->total : null,
            );
        }

        if ($counted && $result->total !== null) {
            return new self($paginator->paginate($pageRequest, $items, $result->total), $queryString, $result->total);
        }

        return new self($paginator->paginateWithoutCount($pageRequest, $items, $result->hasMore), $queryString);
    }

    /**
     * The request a relationship's first page is read with: the primary request with
     * its `page` replaced by the first page (so the paginator's own default size
     * applies, never the primary collection's `page[…]`) and its `sort` and `filter`
     * replaced by the relationship's `$query`, empty when the request addresses none.
     */
    public static function firstPageRequest(JsonApiRequestInterface $request, ?RelatedQuery $query = null): JsonApiRequestInterface
    {
        return $request
            ->withQueryParam('sort', \implode(',', $query?->sortFields() ?? []))
            ->withQueryParam('filter', $query->filter ?? [])
            ->withQueryParam('page', ['number' => '1']);
    }

    /**
     * The relationship-object pagination links for `$endpointUri`, built from the
     * page's link set against the plain-form {@see $queryString}; `null` relations
     * are dropped by the caller.
     *
     * @return array<string, Link|null>
     */
    public function linksFor(string $endpointUri): array
    {
        return $this->page->linkSet($endpointUri, $this->queryString);
    }
}
