<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection;

/**
 * A data layer's answer to a collection fetch: the materialized items (already
 * filtered, sorted, and — when the criteria carried a window — windowed), plus
 * the separately-computed total of the whole filtered collection.
 *
 * {@see $total} is non-null exactly when the fetch was windowed **and counted**:
 * the handler needs it to build a count-based page (`links.last`/`meta.page.total`
 * derive from it), and it is the count **before** windowing, never
 * `count($items)`. An unpaginated fetch leaves it `null` and the handler renders
 * a plain collection document.
 *
 * {@see $windowed} distinguishes a count-FREE windowed fetch (a non-countable
 * related to-many, core ADR 0057) from an unpaginated one: both carry a `null`
 * total, but a count-free windowed result was sliced to a page and must render a
 * count-free page — `meta.page`/`links` without `total`/`last`, the `next` link
 * driven by {@see $hasMore} (the data layer typically fetches one item past the
 * window to set it) — so the page is built via
 * {@see \haddowg\JsonApi\Pagination\PaginatorInterface::paginateWithoutCount()}.
 * It is `false` for an unwindowed fetch (plain collection) and `true` for both a
 * counted page (`$total !== null`) and a count-free page.
 *
 * `TEntity` mirrors the producing data layer's entity type — covariant (the value
 * object is readonly), so a `CollectionResult<Article>` satisfies a
 * `CollectionResult<object>` return.
 *
 * @template-covariant TEntity of object
 */
class CollectionResult
{
    /**
     * @param iterable<TEntity> $items
     * @param ?int              $total   the pre-window total of the whole filtered collection, or null when
     *                                   the fetch was not counted (unwindowed, or a count-free windowed fetch)
     * @param bool              $windowed whether the fetch was windowed (a counted OR a count-free page); the
     *                                    handler reads it only when {@see $total} is null, to tell a count-free
     *                                    page from a plain unpaginated collection
     * @param bool              $hasMore for a count-free page ({@see $total} null, {@see $windowed} true), whether
     *                                   a further page follows — drives the `next` link without a COUNT
     */
    public function __construct(
        public readonly iterable $items,
        public readonly ?int $total = null,
        public readonly bool $windowed = false,
        public readonly bool $hasMore = false,
    ) {}
}
