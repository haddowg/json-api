# A relationship-queries profile for per-relationship sort and filter

A client can now filter and sort a **relationship's** linkage from the primary
request — whether the relationship is rendered as `?include` linkage, as
links-only linkage, or at its own endpoint — through a negotiated JSON:API
**profile**, `RelationshipQueriesProfile`
(`https://haddowg.dev/profiles/relationship-queries`). This is slice 2 of
relationship counting/pagination, building on slice 1's countable relations,
count-free paginators, and the relationship render hook (ADR 0057).

## The query-parameter families

The profile reserves two implementation-specific query-parameter families, both
spec-compliant because each base carries a non a-z character (a capital `Q`):
`relatedQuery` (canonical) and `rQ` (shorthand alias). The form is

```
relatedQuery[<relationship-path>][sort]=-field,field
relatedQuery[<relationship-path>][filter][<key>]=<value>
rQ[<relationship-path>][sort]=-field
```

The path is keyed by the relationship's **include path** (not its type), and a
dotted path (`relatedQuery[albums.tracks][sort]=year`) is legal in the single
bracket per the family grammar. `page` is deliberately **not** part of this
profile: an addressed relationship always renders page 1 from the primary
request, navigated via its own relationship-object pagination links.

## Opt-in gating, conflict resolution, and validation

The families are parsed **only** when the client negotiated the profile (its URI
present in the `Accept` `profile` media-type parameter); otherwise they are
ignored entirely, which keeps the custom family safe from collision with a
relationship literally named after a reserved family. On a per-`[path][op]`
conflict between the two families the canonical `relatedQuery` **wins** (the
shorthand yields — not a 400). A structurally malformed param under the profile
(a non-array family, a non-string `sort`, a non-array `filter`) is a `400`
`QueryParamMalformed` with `source.parameter` the offending param. Both family
bases pass `validateQueryParams()` unchanged (they carry an uppercase letter).
Semantic validation of the sort/filter **keys** against the addressed
relationship's vocabulary (and that the path resolves and is to-many) is the
host's, since only it knows the resource graph.

## The reader and the render seam

`JsonApiRequestInterface::getRelatedQuery($path)` returns a read-only
`RelatedQuery` (`sort` raw string + `filter` map) for a path, lazily parsed and
wither-invalidated like the other query groups; `RelatedQuery::toPlainQueryString()`
translates it to the **plain-form** (`sort=…&filter[…]=…`) the relationship's own
endpoint uses. Core never queries: the page-1 window (ordered/filtered) is
storage-specific and supplied by the host through a new
`RelationshipPaginationInterface` resolver — injected via
`Server::withRelationshipPagination()` → `ResourceRegistry`, mirroring the
slice-1 `RelationshipCountInterface`. `AbstractRelation::buildToMany()` (and
`MorphToMany`) consult it and attach a `RelationshipPagination` (page + plain-form
query string) to the built relationship; `AbstractRelationship::transform()` then
emits the page's `first`/`prev`/`next` (+ `last` only when the relation is
countable, reusing the slice-1 count-free vs countable distinction) into the
relationship object's own `links` — in plain-form against the
relationship-linkage endpoint, never the profile's `relatedQuery[…]` form. With
no resolver injected (standalone core) no relationship-object pagination links are
emitted.
