# Profiles & pagination

> **Status: stub.** This page is a placeholder to be fleshed out in the
> documentation phase (Phase 5). It records the public surface introduced in
> Phase 2 so the docs phase has an anchor; it is not yet consumer documentation.

## Profiles

`haddowg/json-api` ships general-purpose [JSON:API 1.1
profile](https://jsonapi.org/format/1.1/#profiles) infrastructure. A profile is a
named set of document members and processing rules a server *may* apply to a
response; profiles are **advisory** — a server ignores any profile it does not
recognize (only extensions demand strict client/server agreement).

- Implement `Schema\Profile\ProfileInterface` (or extend `AbstractProfile`):
  `uri()`, `keywords()`, and the `finalizeDocument()` document hook.
- Register profiles in a `Schema\Profile\ProfileRegistry` (reached via the
  server's `profiles()`).
- A response advertises the applied profiles on its `Content-Type` `profile`
  parameter and in top-level `links.profile`, and sets `Vary: Accept`.

## Extensions (`ext`)

The negotiation layer parses the `ext` media-type parameter and rejects
unsupported extensions (`415`/`406`). No extension ships in 1.0; this is the hook
the post-1.0 Atomic Operations extension plugs into.

## Pagination

Pagination is a `Pagination\Paginator` strategy that produces a `Pagination\Page`
value object:

- `PagePaginator` / `OffsetPaginator` / `FixedPagePaginator` — count-based.
- `CursorPaginator` — cursor-based, aligned to the published
  [cursor-pagination profile](https://jsonapi.org/profiles/ethanresnick/cursor-pagination/);
  its page omits the `last` link by design.

Render a paginated collection with `DataResponse::fromPage($page, $resource)`,
which emits `links.{first,prev,next,last}` and `meta.page`.

See [`spec-compliance.md`](./spec-compliance.md) for the spec-coverage detail.
