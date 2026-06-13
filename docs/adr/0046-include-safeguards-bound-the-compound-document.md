# Include safeguards bound the compound document

Core gains three composable include safeguards so a compound document can be
constrained and always terminates: a **per-relation includable opt-out**
(`AbstractRelation::cannotBeIncluded()` / `RelationInterface::isIncludable()`), a
**root-scoped allowed-include-paths whitelist**, and a **maximum include depth**
(server default with a per-resource override). They are needed because, without
them, every declared relationship was includable at every path and nested
`?include` + default-include cascades were unbounded — a mutual default-include
cycle (A default-includes B, B default-includes A) infinite-recurses the renderer
— and there was no way to allow a relation from its own resource yet forbid it as
a nested path from a parent.

The three controls a serializer may carry live on a new opt-in capability
interface `haddowg\JsonApi\Serializer\IncludeControlsInterface`
(`getNonIncludableRelationships(object): list<string>`, `maxIncludeDepth(): ?int`,
`getAllowedIncludePaths(): ?array`) — deliberately **not** on `SerializerInterface`,
so standalone serializers and existing test doubles are unaffected; the transformer
and documents read it via `instanceof` (mirroring `UriTypeAwareInterface`), and a
serializer that does not implement it is fully unrestricted (back-compatible).
`AbstractResource` implements it with concrete defaults — non-includable relations
derived from `relationFields()` where `!isIncludable()`, no depth override, no path
whitelist — so every existing subclass satisfies it without edits.
`PolymorphicSerializer` delegates `getNonIncludableRelationships()` to the resolved
inner serializer and is unrestricted on the two root-scoped controls (it only ever
serializes related members, never a primary/root).

Depth is the number of relationship hops from the primary resource
(`?include=a.b.c` is depth 3); a cap of N allows depth ≤ N and 400s deeper
requests. The server default threads from `Server::maxIncludeDepth()` into
`ResourceDocumentTransformation` exactly the way `baseUri` already travels, and is
resolved per-render as `primary override ?? server default`, normalised so `<= 0`
(and `null`) means **unlimited** — core stays unopinionated, leaving the
opinionated default of 3 to the bundle. The document layer evaluates the
root-scoped checks once, up front, against the request's primary resource: the
allowed-paths whitelist rejects any requested path that is neither a listed path
nor an ancestor of one (so listing a deep path implies its intermediates), and the
depth check rejects any over-deep requested path — both raising
`InclusionNotAllowed` (code `INCLUSION_NOT_ALLOWED`) / `InclusionDepthExceeded`
(code `INCLUSION_DEPTH_EXCEEDED`), each a 400 with `source.parameter` on `include`.
The per-relation opt-out is enforced per-resource-level inside the transformer
(it is a property of each hop's resource), and the recursion descent is
additionally gated by depth — past the cap the compound expansion is **silently
skipped** while the linkage identifier is still emitted, which halts the default
cascade and guarantees termination of mutual default-include cycles. The three
controls compose: a requested include path is permitted only if every hop's
relation is includable (A), it is within the effective max depth (B), and it is in
the root's allowed paths when one is set (C).
