# Optional self-described paginator OpenAPI kind

A `PaginatorInterface` carries no notion of its OpenAPI shape, so the bundle's
OpenAPI generator had to `instanceof`-discriminate the concrete strategy to choose
which `page[…]` query parameters to enumerate — and silently projected any
unrecognized custom paginator as the count-based `Page` kind.

We add an opt-in `DescribesPaginatorKindInterface` (alongside `PaginatorInterface`)
whose single `paginatorKind(): PaginatorKind` method lets a strategy self-describe;
the four built-in paginators implement it (`PagePaginator`/`FixedPagePaginator` →
`Page`, `OffsetPaginator` → `Offset`, `CursorPaginator` → `Cursor`). It stays
optional rather than folded into `PaginatorInterface` so a custom paginator that
doesn't implement it still works: the bundle consults the interface first and
otherwise keeps its class-map of the built-ins, falling back to the
JSON:API-conventional `Page` projection for an unknown strategy — turning today's
silent default into an author-overridable one without a breaking contract change.
Mirrors the optional, `instanceof`-read `DeclaresFieldNamesInterface` serializer
capability that serves the same OpenAPI/strict-validation seam.
