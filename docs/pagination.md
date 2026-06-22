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
public function pagination(?\haddowg\JsonApi\Pagination\PaginatorInterface $serverDefault): ?\haddowg\JsonApi\Pagination\PaginatorInterface
{
    return PagePaginator::make()->withDefaultPerPage(25);
}
```

`pagination()` receives the **resolved server-default paginator** and its return is
the single source of truth — used verbatim:

- return `$serverDefault` (or don't override `pagination()` — that's the base) to
  **inherit** the server default;
- return a paginator to **pin** that strategy for this resource;
- return `null` to **disable** pagination — the collection is fetched whole and
  `DataResponse::fromCollection()` serves the entire list (with `meta.total`, see
  [Counting and totals](#counting-and-totals)).

(Pass `null` to `withDefaultPaginator()` and the server has no default, so a
non-overriding resource is unpaginated too.)

## The two-method contract

Every strategy implements
[`PaginatorInterface`](../src/Pagination/PaginatorInterface.php). Two of its methods
do the count-based work, running at different points in your fetch loop:

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
garbage input. (The interface's third method,
[`paginateWithoutCount()`](#count-free-pages), is the no-`COUNT` variant of
`paginate()`.)

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
$paginator = $resource->pagination($server->defaultPaginator());

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
clamps to a valid slice and still returns `200`. `WindowInterface` is the open seam:
a data layer narrows on the concrete window type it knows how to execute — the three
count-based strategies all produce an `OffsetWindow`, while
[cursor pagination](#cursor-pagination) produces a
[`CursorWindow`](../src/Pagination/CursorWindow.php) (a limit plus the decoded
`after`/`before` boundaries) that a keyset-capable layer executes instead.

### The shared window executor

The window → count → slice loop above is the same in every store, so the library
ships it as a storage-agnostic core seam:
[`WindowExecutor`](../src/Collection/WindowExecutor.php). It references only
core/PHP types and takes the store-specific work — materialize the whole filtered
set, count it, fetch a windowed page, probe one item past a page — as **closures**,
so a Doctrine layer passes `LIMIT`/`OFFSET`/`COUNT` push-down closures, an in-memory
layer passes `array_slice`/`count` closures, and both get the identical branch
logic:

- **no window** → the whole filtered set, no count;
- **a counted page** → count the pre-window total, fetch the window;
- **a count-free page** → no `COUNT`; probe `limit + 1` and a surplus item proves a
  further page (see the [count-free note](#count-free-pages) below);
- **a `CursorWindow`** → its own entry point `runCursor()` (keyset, count-free).

`run()` returns a [`CollectionResult`](../src/Collection/CollectionResult.php) —
the materialized items plus the pre-window total (non-null only on a counted page),
a `windowed` flag, and a `hasMore` flag for the count-free branch. The handler then
narrows on it to build the right `Page`. (A keyset fetch returns the
[`CursorCollectionResult`](../src/Collection/CursorCollectionResult.php) subtype,
which additionally carries the minted boundary cursors.) The
[`InMemoryRepository`](../examples/music-catalog/src/Data/InMemoryRepository.php) in
the worked example runs the loop inline for clarity; a real provider hands the four
closures to `WindowExecutor` once and gets every branch for free.

## The four strategies

All four strategies are `final readonly`, built with a `make()` named constructor
and refined with immutable `with…()` helpers. An absent or non-numeric `page[…]`
value falls back to the configured default (matching the request-side parsing rule
— it never throws).

| Strategy | Reads | Defaults (keys / values) |
|---|---|---|
| [`PagePaginator`](../src/Pagination/PagePaginator.php) | `page[number]` / `page[size]` | `number`/`size`, page `1`, per-page `15`, max-per-page `100` |
| [`OffsetPaginator`](../src/Pagination/OffsetPaginator.php) | `page[offset]` / `page[limit]` | `offset`/`limit`, offset `0`, limit `15`, max-per-page `100` |
| [`FixedPagePaginator`](../src/Pagination/FixedPagePaginator.php) | `page[number]` only | `number`, page `1`, fixed `size` `15` (server-set, never echoed) |
| [`CursorPaginator`](../src/Pagination/CursorPaginator.php) | `page[size]` / `page[after]` / `page[before]` | `size`, default size `15`, max-per-page `100` |

All four implement the same `PaginatorInterface` seam — `window()` returns the
fetch window a data layer pushes down. The first three are count-based: their
window is an [`OffsetWindow`](#the-fetch-window) and `paginate()` builds a page from
the windowed items and a separately-computed total. The fourth,
[`CursorPaginator`](#cursor-pagination), is **count-free**: its window is a
`CursorWindow` and its render path is `fromBoundaries()` rather than `paginate()` —
it is covered separately under [Cursor pagination](#cursor-pagination) below. The
three client-size-controlled strategies cap `page[size]`/`page[limit]` — see
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

### Count-free pages

A `COUNT(*)` over the whole filtered collection is often the most expensive part of
a paginated fetch, and some collections cannot be counted at all (a non-countable
related to-many — see [countable relations](related-endpoints.md)). The three
count-based strategies therefore expose a **count-free mode** alongside `paginate()`:

```php
public function paginateWithoutCount(JsonApiRequestInterface $request, iterable $items, bool $hasMore): PageInterface;
```

`paginateWithoutCount()` builds the page **without a total**: it omits
`meta.page.total` and the `last` link, keeps `self`/`first`/`prev`, and derives
`next` from `$hasMore` rather than from the total. Your store learns `$hasMore`
without a `COUNT` by fetching **one item past the window** (`limit + 1`) — a surplus
row proves a further page follows. This is exactly the
[`WindowExecutor`](#the-shared-window-executor) count-free branch, and the same
count-free shape [cursor pagination](#cursor-pagination) is built on.

**Count-free is the default.** A paginator never asks to run the `COUNT` unless told
to: `PaginatorInterface::wantsCount()` is `false` until an author flips it. A handler
that honours the flag renders a plain `GET /articles?page[size]=2` count-free — no
`meta.page.total`, no `last` link, `next` driven by `hasMore`, zero `COUNT` queries.
(This example's repository counts its in-memory slice eagerly, so its pages always
carry the total; a store where `COUNT` is expensive reads `wantsCount()` and calls
`paginateWithoutCount()` instead.) See [Counting and totals](#counting-and-totals)
for how to turn counting on.

## Counting and totals

Counting is **opt-in**, and a total — wherever it appears — is computed once and
rendered consistently. A paginator advertises whether it wants the `COUNT` via
`wantsCount()` (default `false`), which a host's handler reads to choose `paginate()`
vs `paginateWithoutCount()`. There are two levers to turn counting on:

- **`withCount()`** on a count-based paginator (author-always): the paginator runs
  the `COUNT` on **every** paged request, so `meta.page.total` and the `last` link are
  always present. No profile or param needed.

  ```php
  public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
  {
      return PagePaginator::make()->withDefaultPerPage(25)->withCount();
  }
  ```

- **`countable()`** on the resource (client-on-demand): the resource declares its
  primary collection countable, and a client requests the total per request with the
  reserved [`?withCount=_self_`](profiles/countable.md) token (under the negotiated
  Countable profile). A `?withCount=_self_` against a resource that is **not**
  `countable()` is a `400`.

  ```php
  // The resource opts in once …
  (new ArticleResource())->countable();
  // … then a client asks per request:
  // GET /articles?page[size]=2&withCount=_self_
  ```

When a total is computed — for either reason — the same number is written to the
**top-level `meta.total`** (the universal cardinality slot) and, additionally, to
**`meta.page.total`** when the collection is paginated; never counted twice. The
cursor strategy is inherently count-free and takes neither lever.

**No paginator ⇒ free total.** If the resolved paginator is `null` (no server
default, none on the resource, or `withoutPagination()` on a relation), the collection
is fetched whole — so its size is already known and counting is free. In that case
`meta.total` is rendered **unconditionally** (even unrequested); there is no
`meta.page.total` (no pagination).

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

Cursor (keyset) pagination has a different *shape* — no total, opaque boundary
tokens — but it lives under the **same seam** as the count-based strategies:
[`CursorPaginator`](../src/Pagination/CursorPaginator.php) *implements*
`PaginatorInterface`, so it is a drop-in default for a resource, a relation or the
server. Its `window()` returns a [`CursorWindow`](../src/Pagination/CursorWindow.php)
(the resolved size plus the decoded `page[after]` / `page[before]` boundaries) that
a keyset-capable data layer executes exactly as it narrows on the
[`OffsetWindow`](#the-fetch-window) — see [the fetch window](#the-fetch-window).
A cursor page has **no total count by design** (computing one would defeat the
purpose of cursors), so it is inherently count-free and emits no `last` link.

Its `prev`/`next` boundaries are the cursors of the returned items, which only the
executing provider can mint (it owns the row → boundary-value reader). So the
**cursor render path is not the count-based `paginate()`** — it is the dedicated
`fromBoundaries()`, which takes the minted boundary cursors and the
has-next / has-previous flags directly rather than a total:

```php
public function fromBoundaries(
    JsonApiRequestInterface $request,
    iterable $items,
    int|string $cursorBefore,    // cursor of the first returned item (for `prev`)
    int|string $cursorAfter,     // cursor of the last returned item (for `next`)
    bool $hasNext,
    bool $hasPrevious,
    int|string|null $from = null, // id of the first row (for `meta.page.from`)
    int|string|null $to = null,   // id of the last row (for `meta.page.to`)
): CursorBasedPage;
```

```php
use haddowg\JsonApi\Pagination\CursorPaginator;

$window = $paginator->window($request);            // CursorWindow: size + boundaries
// … the provider runs the keyset fetch for $window, returning the page items
//    plus the boundary cursors it minted for the first and last rows …

$page = CursorPaginator::make()
    ->withDefaultSize(20)
    ->fromBoundaries($request, $items, $firstCursor, $lastCursor, $hasNext, $hasPrevious);

return DataResponse::fromPage($page, $server->serializerFor('tracks'));
```

The two interface methods — `paginate($request, $items, $totalItems)` and
`paginateWithoutCount($request, $items, $hasMore)` — are present only for
`PaginatorInterface` conformance: a cursor strategy never derives a total, so the
`$totalItems` argument is ignored and both build a page **without** boundary cursors
(no `prev`/`next`). Use `fromBoundaries()` for a real keyset page.

`CursorPaginator` reads `page[size]` (rekey with `withSizeKey()`) and emits
`page[after]` / `page[before]` cursors; the size is [capped](#capping-the-page-size)
at `100` by default like the other client-controlled strategies. The produced
`CursorBasedPage` carries the published
[cursor-pagination profile](profiles.md) (`CursorPaginationProfile`, URI
`https://jsonapi.org/profiles/ethanresnick/cursor-pagination/`), so a
cursor-paginated response advertises the profile on its `Content-Type` and in
`jsonapi.profile` — **provided** the [server](server.md) has registered it with
`withProfile(new CursorPaginationProfile())`. The catalog registers it in
[`bootstrap.php`](../examples/music-catalog/src/bootstrap.php) for exactly this.

Its `meta.page` carries `perPage` and a `hasMore` flag (plus `from`/`to` ids when
the page is non-empty) — never a `total`, since there is none:

```json
{ "page": { "perPage": 20, "from": "4", "to": "23", "hasMore": true } }
```

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
| `CursorBasedPage` | `first`, `prev`, `next` (**no `self` or `last`** by design) | `perPage`, `hasMore` (+ `from`, `to` on a non-empty page; **never `total`**) |

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
HasMany::make('tracks', 'tracks')
    ->paginate(PagePaginator::make()->withDefaultPerPage(2)),
```

[`PlaylistResource`](../examples/music-catalog/src/Resource/PlaylistResource.php)
does the same for its `tracks` relation. A to-one relation has no collection and
ignores `paginate()`. Opt a relation out of pagination entirely with
`withoutPagination()` — its related collection is then fetched whole (and renders
`meta.total` unconditionally, see [Counting and totals](#counting-and-totals)).

The effective strategy for a related collection resolves through a three-step
fallback chain — **relation → related resource → server default** — which the
handler threads through `pagination()`: the resolved fallback is passed in, and the
relation returns its own paginator over it (or `null`, via `withoutPagination()`):

```php
$fallback = $relatedResource?->pagination($server->defaultPaginator())
    ?? $server->defaultPaginator();
$paginator = $relation->pagination($fallback);
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
