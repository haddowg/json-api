# Project filter/sort/page on the to-many relationship (linkage) endpoint

The to-many relationship (linkage) endpoint
(`GET /{type}/{id}/relationships/{rel}`) projected no query parameters — it was
modelled as returning the whole linkage. But a relationship-linkage collection is
queryable and paginated at full parity with its related-resource twin
(`GET /{type}/{id}/{rel}`): a host windows the linkage to page 1 of the relation's
paginator and supplies it out-of-band through the existing
{@see RelationshipLinkageInterface} / {@see RelationshipPaginationInterface} seams,
so the relationship object renders the filtered/sorted page-1 linkage with its
`first`/`prev`/`next`/`last` links — no core render change.

The operation projector now mirrors the to-many *related* endpoint for a
**monomorphic** to-many relationship endpoint: it projects the merged `filter[]` +
`sort` vocabulary (`relatedType.filters() ⊕ relation.filters()`, the relation
winning) plus the relation's `page[]` parameters. It does **not** project
`include`/`fields[]` — a relationship endpoint renders linkage, not the related
resources. A **to-one** relationship endpoint is unchanged (filter only — a to-one
`400`s on `sort`/`page`); the response (linkage-document) schema already permits
arbitrary further links, so no schema change was needed.

A **polymorphic** to-many relationship endpoint (members span types — no single
related provider or shared vocabulary) takes **no** query parameters: querying its
linkage is unsupported, and the host rejects a requested `filter`/`sort`/`page`
there with a `400` rather than projecting parameters it would silently ignore. (The
polymorphic *related* endpoint is independent — it still pages where the relation
declares a paginator.)
