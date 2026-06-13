# Paginating collections

This page shows you how to break a collection into pages: pick a strategy, fetch
exactly the slice the request asks for, and let the library emit the
`links.{first,prev,next,last}` and `meta.page` that let a client walk the rest.

Pagination is two collaborating pieces:

- a **strategy** — it reads the request's `page[…]` parameters and produces a
  **`Page`** value object holding the items for that page plus the link/meta to
  emit; and
- the **`Page`** itself — pagination state lives here, on the value object, never
  on a collection or a domain object. A collection you do not paginate carries no
  pagination concerns at all.

You wire one strategy as the default and override it per resource (or per
relation) where you need a different shape. The library never touches your data
layer, so *applying* the strategy — loading the right slice and counting the
total — happens in your collection handler. The two-method contract below is
designed precisely so that loop pushes down to a `LIMIT`/`OFFSET` (or an
`array_slice`) instead of loading everything.

## Declaring a paginator

The simplest wiring is a server-wide default. The music catalog registers a
[`PagePaginator`](#the-four-strategies) with a ten-per-page default in
[`bootstrap.php`](../examples/music-catalog/src/bootstrap.php):

```php
use haddowg\JsonApi\Pagination\PagePaginator;

$server = Server::make()
    ->withBaseUri('https://music.example')
    // …
    ->withDefaultPaginator(PagePaginator::make()->withDefaultPerPage(10));
```

Every collection now paginates with `page[number]` / `page[size]` and a default
page size of ten, unless a resource or relation declares its own. A
[Resource](resources.md) overrides the default by implementing `pagination()`:

```php
public function pagination(): ?\haddowg\JsonApi\Pagination\PaginatorInterface
{
    return PagePaginator::make()->withDefaultPerPage(25);
}
```

Returning `null` (the default on `AbstractResource`) defers to the server's
default paginator. Pass `null` to `withDefaultPaginator()` and a collection with
no per-resource override is unpaginated — `DataResponse::fromCollection()` serves
the whole list.

## The two-method contract

A count-based strategy implements
[`PaginatorInterface`](../src/Pagination/PaginatorInterface.php), which has two
methods that run at different points in your fetch loop:

```php
public function window(JsonApiRequestInterface $request): WindowInterface;
public function paginate(JsonApiRequestInterface $request, iterable $items, int $totalItems): PageInterface;
```

`window()` runs **first**, before any items are materialised: it reads the
`page[…]` parameters and returns the slice your store must fetch, so you can push
that down to a query. `paginate()` runs **last**: it wraps the items you already
fetched for that window plus the separately-computed total of the whole filtered
collection. Pages never slice — `paginate()` trusts that `$items` is exactly the
window — so the two methods share one derivation and always agree, even on
garbage input.

The [`InMemoryRepository`](../examples/music-catalog/src/Data/InMemoryRepository.php)
runs the canonical window → slice → count → paginate loop:

```php
$window = $paginator->window($request);
$total = \count($rows);

$slice = $window instanceof OffsetWindow
    ? \array_slice($rows, $window->offset, $window->limit)
    : $rows;

return $paginator->paginate($request, $slice, $total);
```

Replace `\array_slice` with `LIMIT $window->limit OFFSET $window->offset` and
`\count` with a `COUNT(*)` and the same loop pushes down to SQL. The handler picks
the strategy (`resource → server default`) and hands the resulting `Page` to
[`DataResponse::fromPage()`](responses.md), seen in
[`MusicCatalogHandler`](../examples/music-catalog/src/Handler/MusicCatalogHandler.php):

```php
$paginator = $resource->pagination() ?? $server->defaultPaginator();

$result = $this->repository->fetchCollection(/* … */ $paginator);

if ($result instanceof PageInterface) {
    return DataResponse::fromPage($result, $serializer);
}

return DataResponse::fromCollection($result, $serializer);
```

### The fetch window

[`WindowInterface`](../src/Pagination/WindowInterface.php) is the strategy-shaped
handoff between `window()` and your store. The three count-based strategies all
produce an [`OffsetWindow`](../src/Pagination/OffsetWindow.php) — public readonly
`offset` and `limit`, both normalised to `>= 0` at construction:

```php
final readonly class OffsetWindow implements WindowInterface
{
    public function __construct(int $offset, int $limit)
    {
        $this->offset = \max(0, $offset);
        $this->limit = \max(0, $limit);
    }
}
```

Because the values are pre-normalised, your data layer hands them straight to
`LIMIT`/`OFFSET` (or `array_slice`) without re-validating. Garbage `page[…]` input
therefore yields a sane (possibly empty) window, never a `400` — `?page[number]=-5&page[size]=abc`
clamps to a valid slice and still returns `200`. A data layer narrows on the
concrete window type it knows how to execute; the interface keeps the seam open
for a future cursor-shaped window.

## The four strategies

All three count-based strategies are `final readonly`, built with a `make()` named
constructor and refined with immutable `with…()` helpers. An absent or non-numeric
`page[…]` value falls back to the configured default (matching the request-side
parsing rule — it never throws).

| Strategy | Reads | Defaults (keys / values) |
|---|---|---|
| [`PagePaginator`](../src/Pagination/PagePaginator.php) | `page[number]` / `page[size]` | `number`/`size`, page `1`, per-page `15`, max-per-page `100` |
| [`OffsetPaginator`](../src/Pagination/OffsetPaginator.php) | `page[offset]` / `page[limit]` | `offset`/`limit`, offset `0`, limit `15`, max-per-page `100` |
| [`FixedPagePaginator`](../src/Pagination/FixedPagePaginator.php) | `page[number]` only | `number`, page `1`, fixed `size` `15` (server-set, never echoed) |
| [`CursorPaginator`](../src/Pagination/CursorPaginator.php) | `page[size]` / `page[after]` / `page[before]` | `size`, default size `15`, max-per-page `100` |

Three are count-based and share the two-method `PaginatorInterface` contract above;
the fourth, [`CursorPaginator`](#cursor-pagination), has a different shape and is
covered separately under [Cursor pagination](#cursor-pagination) below. The three
client-size-controlled strategies cap `page[size]`/`page[limit]` — see
[Capping the page size](#capping-the-page-size).

### PagePaginator — the baseline

`page[number]` / `page[size]`. Override the keys and defaults with
`withPageKey()`, `withPerPageKey()`, `withDefaultPage()` and `withDefaultPerPage()`:

```php
$paginator = PagePaginator::make()
    ->withDefaultPerPage(20)
    ->withPerPageKey('size');
```

Each helper returns a new instance, so a configured paginator is shared safely. Its
`window()` derives `offset = (number - 1) * size`; its `paginate()` returns a
`PageBasedPage` carrying the full `first`/`prev`/`next`/`last` set.

### OffsetPaginator — offset and limit

The same shape with row-offset semantics: `page[offset]` / `page[limit]`, keyed and
defaulted with `withOffsetKey()`, `withLimitKey()`, `withDefaultOffset()` and
`withDefaultLimit()`. Its `meta.page` reports `offset`/`limit` rather than
`currentPage`/`perPage`.

### FixedPagePaginator — server-fixed size

For endpoints where the server fixes the page size and the client only sends
`page[number]`:

```php
$paginator = FixedPagePaginator::make(50); // 50-per-page, fixed
```

The configured `size` (default `15`) is used solely to compute the last page — it
is **never** echoed in the emitted links. Refine with `withSize()`, `withPageKey()`
and `withDefaultPage()`. It has no page-size cap because the client never controls
its size.

## Capping the page size

The client controls `page[size]` (and `page[limit]`), so without a ceiling a
single request can ask for `page[size]=1000000` and force your store to fetch a
million rows — a denial-of-service vector. The page-size strategies therefore
**cap** the resolved size: `PagePaginator`, `OffsetPaginator` and `CursorPaginator`
clamp an over-large request down to a maximum, the same clamp-don't-`400` stance
as every other garbage `page[…]` value. **The cap is on by default at `100`**, so
every store is protected without any configuration:

```php
// page[size]=1000000 → 100 items, 200 OK; meta.page.perPage reads 100.
PagePaginator::make();
```

Tune it with `withMaxPerPage()` — the cap only clamps **down**, never raising a
smaller request, and the default-per-page is untouched as long as it sits at or
below the cap:

```php
$paginator = PagePaginator::make()
    ->withDefaultPerPage(25)
    ->withMaxPerPage(50); // page[size]=1000 → 50; page[size]=10 → 10; no page → 25
```

The cap applies in both places that read the size, so they always agree: the
[fetch window](#the-fetch-window) your store loads (`window()->limit`) **and** the
rendered `meta.page` size. An over-large `page[size]` thus returns the capped
number of items with a `200`, and `meta.page.perPage` reports the cap — never the
abusive number.

Pass `0` to **disable** the cap (unlimited):

```php
PagePaginator::make()->withMaxPerPage(0); // honours any page[size]
```

The [music catalog](../examples/music-catalog/src/bootstrap.php) caps its default
paginator at `50` to witness the clamp; `page[size]=1000000` there returns at most
`50` items with `meta.page.perPage: 50`.

## Cursor pagination

Cursor pagination has a different shape, so
[`CursorPaginator`](../src/Pagination/CursorPaginator.php) is a **standalone**
fluent strategy that does **not** implement `PaginatorInterface`. A cursor page has
no total count by design — computing one would defeat the purpose of cursors — so
it has no `window()` and emits no `last` link. Its `prev`/`next` boundaries are the
cursors of the returned items, which only you can extract from the domain data, so
`paginate()` takes those cursors and the has-next / has-previous flags directly
rather than a total:

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

return DataResponse::fromPage($page, $server->serializerFor('tracks'));
```

`CursorPaginator` reads `page[size]` (rekey with `withSizeKey()`) and emits
`page[after]` / `page[before]` cursors. The produced `CursorBasedPage` carries the
published [cursor-pagination profile](profiles.md) (`CursorPaginationProfile`, URI
`https://jsonapi.org/profiles/ethanresnick/cursor-pagination/`), so a
cursor-paginated response advertises the profile on its `Content-Type` and in
`links.profile` — **provided** the [server](server.md) has registered it with
`withProfile(new CursorPaginationProfile())`. The catalog registers it in
[`bootstrap.php`](../examples/music-catalog/src/bootstrap.php) for exactly this.

## The `Page` value object

[`PageInterface`](../src/Pagination/PageInterface.php) is generic (`PageInterface<T>`)
and **iterable** — it extends `\IteratorAggregate`, re-keying items to integer
indices — so `DataResponse::fromPage($page, …)` walks the items without unwrapping.
It exposes three methods the response layer calls:

```php
public function linkSet(string $uri, string $queryString): array; // array<string, Link|null>
public function pageMeta(): array;                                 // array<string, mixed>
public function profile(): ?ProfileInterface;
```

`linkSet()` returns the pagination links keyed by relation
(`self`/`first`/`prev`/`next`/`last`); a `null` value means that relation is
omitted for this page (e.g. `prev` on the first page, or `last` for cursor pages).
Links are **absolute and query-string-preserving**: the strategy's `page[…]`
parameters are merged over the request's current query string, so `filter`, `sort`
and sparse fieldsets survive across pages — `GET /tracks?page[size]=2&sort=trackNumber`
emits a `next` link that still carries `sort=trackNumber`. `pageMeta()` returns the
`meta.page` contents (each strategy's shape differs — see the table below).
`profile()` is the third method: it returns the profile a page activates (the
cursor page returns its profile; the count-based pages return `null`).

The concrete pages — `PageBasedPage`, `OffsetBasedPage`, `FixedPagePage`,
`CursorBasedPage` — are the subtypes the strategies return. You rarely construct
them directly; let the strategy do it.

### Emitted page shapes

The four pages side by side — which links they emit and what `meta.page` carries:

| Page | Links emitted | `meta.page` keys |
|---|---|---|
| `PageBasedPage` | `self`, `first`, `prev`, `next`, `last` | `currentPage`, `perPage`, `from`, `to`, `total`, `lastPage` |
| `OffsetBasedPage` | `self`, `first`, `prev`, `next`, `last` | `offset`, `limit`, `from`, `to`, `total` |
| `FixedPagePage` | `self`, `first`, `prev`, `next`, `last` | `currentPage`, `total`, `lastPage` (no `perPage` — size is server-fixed) |
| `CursorBasedPage` | `first`, `prev`, `next` (**no `self` or `last`** by design) | `perPage`, `hasMore` |

Two defensive behaviours apply to the count-based pages:

- **An empty or degenerate collection suppresses the whole link set.** When
  `total <= 0` or the effective page size / limit is `<= 0`, `linkSet()` returns
  every relation as `null` — no `first`/`last` pointing at nothing.
- **`self`, `prev` and `next` are `null` at the boundaries.** `prev` is omitted on
  the first page, `next` on the last, and `self` when the requested page falls
  outside the valid range. The cursor page additionally omits `last` always (it has
  no total to locate a last page).

So `GET /tracks?page[number]=1&page[size]=2` over three tracks emits `first`,
`next` and `last` but no `prev`; `page[number]=2` (the last page) emits `prev` but
no `next`, and its `meta.page` reads `currentPage: 2`, `perPage: 2`, `total: 3`,
`lastPage: 2`.

## Per-relation pagination

A to-many relationship's related-collection endpoint (`GET /{type}/{id}/{rel}`)
paginates independently of the primary collection. Declare a per-relation strategy
with `paginate()` on the relation builder —
[`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php) windows
an album's tracks two-per-page:

```php
HasMany::make('tracks')
    ->type('tracks')
    ->paginate(PagePaginator::make()->withDefaultPerPage(2))
    ->linkageOnlyWhenLoaded(),
```

[`PlaylistResource`](../examples/music-catalog/src/Resource/PlaylistResource.php)
does the same for its `tracks` relation. A to-one relation has no collection and
ignores `paginate()`.

The effective strategy for a related collection resolves through a three-step
fallback chain — **relation → related resource → server default** — which the
handler reads off the relation:

```php
$paginator = $relation->pagination()
    ?? $relatedResource?->pagination()
    ?? $server->defaultPaginator();
```

The related slice then renders through
[`RelatedResponse::fromPage()`](responses.md) (the primary-collection twin is
`DataResponse::fromPage()`). `RelatedResponse::fromPage()` scopes the pagination
links to the *related* URL, so `next`/`prev` on `GET /albums/1/tracks` point back
at the related endpoint, not at `/tracks`. A polymorphic to-many carries no shared
filter/sort vocabulary, so on it `filter`/`sort` are a `400` and only `page`
windows the mixed members — see [related endpoints](related-endpoints.md).

## Custom strategies

Supply your own count-based strategy by implementing `PaginatorInterface` and
returning whatever `PageInterface` subtype suits — a custom `meta.page` shape, a
keyset window, a different link policy. Register it per resource (`pagination()`),
per relation (`paginate()`) or server-wide (`withDefaultPaginator()`); the
window → slice → count → paginate loop is unchanged.

## Next / See also

- [Responses](responses.md) — `DataResponse::fromPage()` / `fromCollection()` and
  `RelatedResponse::fromPage()`.
- [Profiles](profiles.md) — the cursor-pagination profile and how a page activates it.
- [Filters](filters.md) and [Sorts](sorts.md) — the criteria preserved across
  pagination links.
- [Resource classes](resources.md) — declaring a per-type default `pagination()`.
- [Server](server.md) — the server-wide default paginator.
- [Related endpoints](related-endpoints.md) — where per-relation pagination applies.
