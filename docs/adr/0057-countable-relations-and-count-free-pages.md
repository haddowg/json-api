# Countable relations, `?withCount`, and the count-free page

A to-many relation can now be declared **countable**, exposing its cardinality as
`meta.total` on the relationship object, with the count supplied through a seam
(core never computes one) and the page/offset paginators gaining a count-free
mode so a non-countable related collection paginates without a `COUNT`. This is
slice 1 of relationship counting (paginated/filtered/sorted includes are a
separate, later slice).

**`countable()` / `isCountable()`** are a fluent builder + reader on
`AbstractRelation` / `RelationInterface`, default **false**. `countable()` is the
single universal count gate: it governs both the relationship-object `meta.total`
and (consumed by the host) the related-collection endpoint's pagination total.

**`?withCount=rel1,rel2`** is a flat, comma-separated primary-request query
parameter (parsed on `JsonApiRequest`, like `?include` but never dotted, with a
name → name cache and the same wither invalidation). The serializer consults it
via `JsonApiRequestInterface::countsRelationship($name)` /
`getCountedRelationships()`. It carries an uppercase letter, so it is a valid
implementation-specific parameter and is *not* rejected by `validateQueryParams()`.
It is **validated up front, root-scoped** in `AbstractResourceDocument`
(alongside the include allow-list): a name the primary serializer does not declare
countable — not `countable()`, or a to-one — is a `400`
`RelationshipCountNotAllowed` with `source.parameter` `withCount` (mirroring
`InclusionNotAllowed`). The set is read through a new opt-in capability
`CountableControlsInterface::getCountableRelationships()` (read via `instanceof`,
like `IncludeControlsInterface`); a serializer that does not implement it declares
no countable relations, so any `?withCount` against it is rejected — counting is
opt-in. `AbstractResource` implements it from its declared `countable()` to-many
relations.

**The count seam.** `AbstractRelation::buildToMany()` realises the previously
stubbed "future link/meta wiring" hook as a **general per-relationship
meta-contribution** point, `relationshipMeta()`, merged onto the built
relationship; the countable `total` is its first consumer. `MorphToMany`
overrides `buildRelationship()` (it binds a per-member `PolymorphicSerializer`),
so it applies the same `relationshipMeta()` merge before returning — a countable
polymorphic to-many named in `?withCount` renders its cardinality too. The count
value is
storage-specific, so core reads it through `RelationshipCountInterface`
(`countRelationship(model, relation): ?int`), injected through
`SerializerResolverInterface::relationshipCount()` exactly as
`RelationshipLoadStateInterface` is (threaded `Server` → `ResourceRegistry`).
`meta.total` is emitted only when the relation `isCountable()`, the request names
it in `?withCount`, and the resolver returns a non-null count; with no resolver
injected (standalone core) no count is emitted. The meta key is **`total`** — the
same key the count-based pages already emit in `pageMeta()`, so the
relationship-object total and the endpoint pagination total are one semantic.

**The count-free page.** `PageBasedPage` / `OffsetBasedPage` / `FixedPagePage`
take a nullable `totalItems` plus a `hasMore` flag: when `totalItems === null`
(count-free mode) they omit `total` from `pageMeta()` and `last` from `linkSet()`,
keep `self`/`first`/`prev`, and derive `next` from `hasMore` — the page/offset
analogue of the count-free shape `CursorBasedPage` already models (the data layer
fetches one item past the window to learn `hasMore`). `PaginatorInterface` gains
`paginateWithoutCount(request, items, hasMore)` — the "do not count" mode — so a
data layer can paginate a non-countable related collection without ever running a
`COUNT`. The existing `paginate(request, items, int totalItems)` is unchanged, so
every existing call site and the count-based behaviour are untouched; cursor
pagination, inherently count-free, remains outside `PaginatorInterface`.
