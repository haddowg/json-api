# Cursor (keyset) pagination is a window strategy under the WindowExecutor seam

Cursor pagination is built as another `WindowInterface` strategy under the
consolidated collection-windowing seam (ADR 0061): `CursorPaginator` *implements*
`PaginatorInterface` and its `window()` returns a `CursorWindow` (the decoded
keyset boundaries + limit), so a keyset-capable data layer narrows on the window
type exactly as it does for `OffsetWindow` — no resource/server signature change
(Option A). The window carries only the limit and the decoded `after`/`before`
boundaries, deliberately **not** a resolved sort spec: the active sort lives on the
request's collection criteria, and resolving it to keyset columns — together with
the keyset `WHERE`, the staleness check, and minting the new tokens — is an
execution concern owned by the providers (C2 Doctrine / C3 in-memory), because
only they have the entity metadata, the sort vocabulary, and the row → value
reader. This C1 scaffolding therefore builds the *types* (`CursorWindow`,
`CursorBoundary`, the `CursorCollectionResult` subtype that carries the boundary
cursors so the offset `CollectionResult` path stays byte-identical), the opaque
base64url `CursorCodec` (URL-safe, unsigned — a boundary value may legitimately be
`null` for a nullable sort column), the `CursorPaginator::window()`/`fromBoundaries()`
builder, the `WindowExecutor::runCursor()` count-free keyset branch (over-fetch
`limit + 1` for `hasMore`, slice, let the provider mint tokens), and the typed
`MalformedCursor`/`StaleCursor` `400` exceptions — `StaleCursor` is *defined* here
but *thrown* by the providers.

## Decisions

- **Param = `page[size]`** (strict Ethan-Resnick cursor-profile conformance);
  offset keeps `page[limit]`. The cursor tokens are `page[after]` / `page[before]`.
- **Active-sort keyset.** The client's `?sort` drives the keyset; the cursor
  encodes the active-sort columns plus the PK tiebreaker (whose direction matches
  the last sort directive). A request whose sort no longer matches the token's
  columns is stale → `StaleCursor` (resolved in the providers).
- **NULL boundary values are valid.** The codec and `CursorBoundary` carry `null`
  values; the portable IS-NULL-branch keyset with a forced NULL=largest ordering
  is an execution concern deferred to C2/C3.
- **Option A interface.** `CursorPaginator implements PaginatorInterface`;
  `paginate()` is count-free (the `$totalItems` argument is ignored) and the real
  cursor path is `fromBoundaries()`.
- **Subtype result.** `CursorCollectionResult extends CollectionResult` carries the
  minted boundary cursors so the handler narrows on `instanceof`; the offset
  `CollectionResult` path is unchanged.
- **Stale / malformed cursor → `400`** via typed core exceptions with
  `source.parameter` = `page[after]` / `page[before]`.
- **Totals off** (no `last` link, no `COUNT`) in v1.
