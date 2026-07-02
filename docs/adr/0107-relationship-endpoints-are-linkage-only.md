# Relationship endpoints are linkage-only (no compound `included`)

A relationship endpoint (`GET /{type}/{id}/relationships/{rel}`) renders **linkage
only**: the top-level `data` (the resource-identifier array / nullable identifier),
plus `jsonapi`/`meta`/`links`. It never carries a compound `included` member.
`DocumentTransformer::transformRelationshipDocument()` no longer emits `included`, for
a requested `?include` or a resource's default-included relations. `?include` on a
relationship endpoint is tolerated (not a `400`) but inert.

Previously `transformRelationshipDataMembers()` emitted `included` whenever the request
carried `?include`, resolving the include tree **against the parent resource**. On
`GET /articles/1/relationships/comments?include=author` the primary data was the
`comments` linkage yet `included` held the *article's* author — a resource not reachable
from the primary linkage, which is incoherent as a compound document. The projection
never advertised this, and no test exercised it; the behaviour was an accident of
rendering the relationship through the parent serializer.

Linkage-only is the spec-conservative reading (the JSON:API compound-document machinery
is defined for endpoints whose primary data is resources) and matches how the endpoint
is documented. Compound inclusion remains available on the **related** endpoint
(`GET /{type}/{id}/{rel}`), which returns resources and supports `?include`/`?fields`
against the related type. The OpenAPI projection is unchanged in omitting `include`/
`fields`/`included` from the relationship endpoint; separately, the to-many relationship
document now `$ref`s the typed `PaginationLinks` (it is a paginated linkage collection,
ADR 0096) rather than the permissive `Links`. Taken before the 1.0 freeze.
