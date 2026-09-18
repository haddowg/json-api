# A document describes only the types its server registers

Where a relation exposed its related endpoint to a type the server did not register, the
projector synthesized a permissive `<Type>Resource` — `type` const, string `id`, open
`attributes` — so the endpoint returning one had something to `$ref`. That put two
different shapes behind one JSON:API `type` across two documents from the same process,
the open guess here and the real inventory wherever the type is registered, with nothing
in either document marking which was which. We now refuse: `OpenApiProjector::project()`
throws `RelatedTypeNotRegistered`, naming the parent type, the relation, the related type
and the server, and stating the three honest resolutions (register the type here, point
the relation at a type that is registered, or `withoutRelatedEndpoint()`).

The runtime settled it. On a server that registers `favorites` but not `users`, the
synthesized document advertised `200` plus a `users` resource object for
`GET /favorites/{id}/user`; the server cannot resolve a serializer for an unregistered
type, so the endpoint raises the 500-status `NoResourceRegistered`. The linkage never
arrives either — with no serializer to bind, the relationship is built links-only, so the
resource document's relationship carries no `data` member and the relationship endpoint
answers with a linkage document that has none, which the spec requires of it. The
synthesized component was not an under-specified description of something that worked. It
described something that does not exist.

Two narrower readings are deliberately left alone. Two servers serving the same type with
different shapes stays supported — each projects from its own registrations, so each
states a shape it honours, and that is how a v2 API is versioned beside a v1. And a
linkage-only related type still needs no registration: it gets a `ResourceIdentifier`,
which is `{type, id}` and asserts nothing about the resource behind it.

The alternative was to keep synthesizing and mark the guess — an `x-` extension, or a
description sentence. We rejected it because nothing downstream would act on the marker: a
generator emits a type from the schema it is given, and a warning it has no rule for is a
warning it ignores. The configuration has three cheap fixes, so failing the export is the
cheaper signal. This supersedes the part of [ADR 0133](0133-one-projected-type-set-for-every-generated-artifact.md)
that treated synthesized related-only types as part of the contract;
`ProjectedTypes::forServer()` survives unchanged as the accessor a framework integration
keys its JSON Schema bundle from, and `relatedOnly()` becomes the diagnostic — non-empty
means the projection refuses.
