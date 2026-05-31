<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Profile\ProfileInterface;

/**
 * A page of a paginated collection: the items for the page plus the
 * strategy-specific metadata needed to emit `links.{first,prev,next,last}` and
 * `meta.page.{…}`.
 *
 * Replaces yin's `PaginationLinkProviderInterface` + collection-side trait
 * pattern: pagination state lives on the page value object, never on the
 * collection. A page is iterable, so `DataResponse::fromPage($page)` can iterate
 * the items without unwrapping. Strategy-specific subtypes
 * ({@see PageBasedPage}, {@see OffsetBasedPage}, {@see CursorBasedPage},
 * {@see FixedPagePage}) implement the link/meta emission.
 *
 * @template T
 *
 * @extends \IteratorAggregate<int, T>
 *
 * @see https://jsonapi.org/format/1.1/#fetching-pagination
 */
interface Page extends \IteratorAggregate
{
    /**
     * The pagination links for this page, keyed by relation
     * (`self`/`first`/`prev`/`next`/`last`). A `null` value means that relation
     * is not emitted for this page (e.g. `prev` on the first page, or `last` for
     * cursor pagination which omits it by design).
     *
     * @param string $uri         the self URI of the collection endpoint (base URI + path)
     * @param string $queryString the request's current query string, preserved across pages
     *
     * @return array<string, Link|null>
     */
    public function linkSet(string $uri, string $queryString): array;

    /**
     * The contents of the document's `meta.page` member for this page
     * (implementation-specific; `[]` to omit).
     *
     * @return array<string, mixed>
     */
    public function pageMeta(): array;

    /**
     * The profile this page activates, if any (e.g. cursor pagination activates
     * the cursor-pagination profile), or `null` when the strategy has no
     * associated published profile.
     */
    public function profile(): ?ProfileInterface;
}
