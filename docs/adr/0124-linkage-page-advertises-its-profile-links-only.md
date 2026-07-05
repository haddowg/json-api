# A linkage response advertises its page's profile but stays links-only

`IdentifierResponse::withPage()` attaches the page that windowed a relationship-linkage
endpoint (`GET /{type}/{id}/relationships/{rel}`) so a page that activates a profile —
cursor pagination — is advertised in `jsonapi.profile` and the `Content-Type` `profile`
media-type parameter, through the same `AppliesPaginationTrait::appliedPageProfiles()`
wiring `RelatedResponse`/`DataResponse` use. Previously the class had no page path at
all: linkage windowing rides the relationship-pagination seam
(`Server::withRelationshipPagination()`, ADR 0096), which renders only the pagination
**links** via `PageInterface::linkSet()`, so a cursor-windowed linkage emitted
`page[after]`/`page[before]` links while the response could never advertise the profile
those links belong to — and being `final` with a `protected appliedProfiles()`, nothing
downstream could patch it.

The attached page deliberately does **not** touch the document body. The established
offset-windowed linkage convention is links-only: pagination links flow through the
seam, and `meta.page` is not emitted on linkage documents. `withPage()` keeps that —
it affects profile advertisement only, never merges `applyPagination()`'s
links/`meta.page` — so it cannot double-emit against the seam's links, and a page-less
response stays byte-identical. If linkage documents ever grow `meta.page`, that is a
separate decision superseding this one, not a gap in it.
