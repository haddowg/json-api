# Pagination

Pagination in this library is two collaborating pieces: a **strategy** that reads
the request's `page[…]` parameters and produces a **page**, and a `Page` value
object that holds the items for that page plus the metadata needed to emit
`links.{first,prev,next,last}` and `meta.page`. You pick a strategy, hand it the
slice of items for the requested page and the total count, and pass the resulting
page to [`DataResponse::fromPage()`](responses.md). The response layer reads the
page's links and meta and writes them into the document.

> **No collection-trait pattern.** Pagination state lives on the `Page` value
> object, never mixed into a collection or a domain object. A plain collection
> that isn't paginated uses `DataResponse::fromCollection()` and carries no
> pagination concerns at all.

## Strategies

A count-based strategy implements `Pagination\PaginatorInterface`:

```php
public function paginate(JsonApiRequestInterface $request, iterable $items, int $totalItems): PageInterface;
```

It reads the `page[…]` query parameters off the request, combines them with the
`$items` and `$totalItems` you supply, and returns the matching `Page`. Three
count-based strategies ship; all are `final readonly`, built with a `make()` named
constructor and refined with immutable `with…()` helpers. An absent or non-numeric
`page[…]` value silently falls back to the configured default (matching the
request-side parsing rule — it never throws).

| Strategy | Reads | Defaults (keys / values) |
|---|---|---|
| `PagePaginator` | `page[number]` / `page[size]` | `number`/`size`, page `1`, per-page `15` |
| `OffsetPaginator` | `page[offset]` / `page[limit]` | `offset`/`limit`, offset `0`, limit `15` |
| `FixedPagePaginator` | `page[number]` only | `number`, page `1`, fixed size `15` (server-set, never echoed) |

```php
use haddowg\JsonApi\Pagination\PagePaginator;

$paginator = PagePaginator::make()
    ->withDefaultPerPage(20)
    ->withPerPageKey('size');

$page = $paginator->paginate($request, $itemsForThisPage, $totalCount);

return DataResponse::fromPage($page, $server->serializerFor('articles'));
```

`FixedPagePaginator` is for endpoints where the server fixes the page size: the
client only sends `page[number]`, and the configured `size` is used solely to
compute the last page — it is never part of the emitted links. Override the keys
or defaults with the respective `with…()` helpers (`withPageKey()`,
`withDefaultPage()`, `withSize()`, …).

You supply your own count-based strategy by implementing `PaginatorInterface` and returning
whatever `PageInterface` subtype is appropriate.

## Cursor pagination

Cursor pagination has a different shape, so `CursorPaginator` is a **standalone**
fluent strategy that does **not** implement `PaginatorInterface`. A cursor page has no
total count by design (computing one would defeat the purpose of cursors), and its
`prev`/`next` boundaries are the cursors of the returned items — which only you can
extract from the domain data. Its `paginate()` therefore takes the boundary
cursors and the has-next / has-previous flags directly, rather than a total:

```php
public function paginate(
    JsonApiRequestInterface $request,
    iterable $items,
    int|string $cursorBefore,  // cursor of the first returned item (for `prev`)
    int|string $cursorAfter,   // cursor of the last returned item (for `next`)
    bool $hasNext,
    bool $hasPrevious,
): CursorBasedPage;
```

```php
use haddowg\JsonApi\Pagination\CursorPaginator;

$page = CursorPaginator::make()
    ->withDefaultSize(20)
    ->paginate($request, $items, $firstCursor, $lastCursor, $hasNext, $hasPrevious);

return DataResponse::fromPage($page, $server->serializerFor('articles'));
```

`CursorPaginator` reads `page[size]` and emits `page[after]` / `page[before]`
cursors. The produced `CursorBasedPage`:

- emits `first`/`prev`/`next` links but **omits `last` by design** (there is no
  total), and
- carries the published [cursor-pagination profile](profiles.md)
  (`CursorPaginationProfile`, URI
  `https://jsonapi.org/profiles/ethanresnick/cursor-pagination/`), so a
  cursor-paginated response advertises the profile on its `Content-Type` and in
  `links.profile` — provided the [server](server.md) has registered it.

## The `Page` value object

`PageInterface` is generic (`PageInterface<T>`) and **iterable** — it extends `IteratorAggregate`,
re-keying its items to integer indices — so `DataResponse::fromPage($page, …)` can
walk the items directly without unwrapping. It exposes two emission methods the
response layer calls:

```php
public function linkSet(string $uri, string $queryString): array; // array<string, Link|null>
public function pageMeta(): array;                                 // array<string, mixed>
```

`linkSet()` returns the pagination links keyed by relation
(`self`/`first`/`prev`/`next`/`last`); a `null` value means that relation is
omitted for this page (e.g. `prev` on the first page, or `last` for cursor
pages). Links are built by merging the strategy's `page[…]` parameters over the
request's current query string, so unrelated parameters — `filter`, `sort`, sparse
fieldsets — are preserved across pages. `pageMeta()` returns the `meta.page`
contents (each strategy's shape differs: page-based emits `currentPage` / `perPage`
/ `from` / `to` / `total` / `lastPage`; cursor-based emits `perPage` / `hasMore`).

The concrete pages — `PageBasedPage`, `OffsetBasedPage`, `FixedPagePage`,
`CursorBasedPage` — are the subtypes the strategies return. You rarely construct
them directly; let the strategy do it.

## Per-resource and server defaults

A [Resource class](resources.md) can declare its own default strategy by overriding
`pagination(): ?PaginatorInterface`; returning `null` (the default) defers to the
[server's](server.md) default paginator, set with
`Server::withDefaultPaginator()`. Either way, applying the strategy — deciding
which slice of items to load and what the total is — happens in **your** collection
handler: pagination touches your data layer, which the library never does for you.
The strategy turns the request and your numbers into a `Page`; you return it via
`fromPage()`.

## Related pages

- [Responses](responses.md) — `DataResponse::fromPage()` / `fromCollection()`.
- [Profiles](profiles.md) — the cursor-pagination profile and how profiles are advertised.
- [Resource classes](resources.md) — declaring a per-type default `pagination()`.
- [Server](server.md) — the server-wide default paginator.
