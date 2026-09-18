# One projected type set for every generated artifact

A server's OpenAPI document describes its registered types **plus** any type reached by a
relation that exposes its related endpoint — the projector synthesizes a permissive
resource object for the latter, because the endpoint really returns one and a dangling
`$ref` would invalidate the document. That rule lived privately inside
`OpenApiProjector::addUnregisteredRelatedComponents()`, so the framework integrations,
which emit a **second** artifact from the same metadata (the per-type JSON Schema bundle
at `/schemas.json`), had no way to ask for it and keyed their bundle from
`ServerMetadataInterface::types()` instead. The two artifacts therefore described
different type sets: in the reference music catalogue the document covers `users` on the
default server and the bundle does not, and on the admin server the gap is four types.

We promote the rule to a public `OpenApi\ProjectedTypes` and make it the single answer to
"which types does this server describe?" — `registered()`, `relatedOnly()`, and
`forServer()` as their concatenation. The projector keeps emitting exactly what it emitted
before (this changes no document byte); what changes is that an integration can now key
its bundle from `forServer()` and stay aligned by construction rather than by coincidence.

The alternative was to declare the bundle a registered-types-only artifact and document the
difference. We rejected it because the bundle already ships content-free schemas for
registered standalone-serializer types that have no field inventory, so "nothing useful to
say about it" was never the exclusion criterion — and a client validating a related
endpoint's response by looking up its `type` deserves a hit rather than a gap. A
linkage-only related type stays out of the set: it has no resource object in the document
either, so there is no contract to describe.
