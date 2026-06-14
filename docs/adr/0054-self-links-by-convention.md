# Resource and top-level `self` links by convention

The spec RECOMMENDS (SHOULD) that a resource object carry a `links.self` linking
to itself and that a document carry a top-level `links.self` for the URI that
produced it; both were previously absent unless hand-written. We now emit both by
convention, mirroring the existing relationship `self`/`related` convention links.

**Resource `self`** (`{baseUri}/{uriType}/{id}`) is built in `ResourceTransformer`
— the only layer that knows the resolved type + id and the base URI — for every
serializer, unless: the id is empty (a not-yet-persisted resource has no self), a
`getLinks()` already supplied a `self` (a hand-written self wins), or the
serializer opts out. The path segment is the serializer's `uriType()` when it is
`UriTypeAwareInterface` (so a resource whose JSON:API type differs from its URL
segment links correctly), falling back to its JSON:API `type` — exactly as
`AbstractRelationship::conventionLinks()` resolves the parent segment. The opt-out
is a new optional capability `SelfLinkAwareInterface::emitsSelfLink(): bool` (read
via `instanceof`, mirroring `UriTypeAwareInterface`/`IncludeControlsInterface`): a
serializer that does not implement it is treated as emitting, so external
serializers and bare serializer/hydrator pairs are unaffected. `AbstractResource`
implements it, defaulting to `true`, with an overridable `emitsSelfLink()` to opt a
resource out.

**Top-level `self`** (`{server.baseUri}{request.path}` plus the query string when
present) is merged in `AbstractResponse::applyTopLevelSelf()`, reusing the URI
derivation in `AppliesPaginationTrait`. It is applied to the data/resource
documents (single, collection, related, relationship, meta) — **not** error
documents — and is always on (a clean server-level opt-out was not cheap; a
hand-set or paginator-supplied top-level `self` wins because it is applied last,
after pagination, and only when no `self` is already present).
