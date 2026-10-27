# Links schemas are shared per context and carry no examples

Every type and relation had its own links components, each repeating the same member descriptions, the same pagination members and an example of every member twice, once on the member and once on the whole object. In a real server's document they were close to a quarter of the bytes, and since operation examples (ADR 0176) already show each document whole, links included, the per-type examples said nothing the reader couldn't find there. So there is now one component per links context, `ResourceLinks`, `DocumentLinks`, `CollectionLinks`, `RelationshipLinks`, `RelationshipDocumentLinks` and `RelatedDocumentLinks`, which the document schemas `$ref` directly; a type with declared links or action links keeps a `<Type>ResourceLinks` that is an `allOf` of `ResourceLinks` and its named links; and link schemas carry no examples. This supersedes the per-endpoint components and whole-object examples of ADR 0166.

## Considered options

- Keep a thin `<Type>DocumentLinks` and the other per-type names as wrappers around the shared components. A generator would keep its names, but each wrapper is a `$ref` with nothing beside it, and the names would have to stay forever for no information.
- Keep listing only the members a relation's exposed endpoints allow. That needs a component per relation shape, which is most of what this change removes. The shared component lists every member the context can render, and its description says when each is present.
- Write the named links as sibling keywords beside a `$ref`. OAS 3.1 allows it, but tools reading OAS 3.0 semantics drop the siblings (ADR 0165), so the per-type component is an `allOf`.

## Consequences

Every document's structure moves, so contract 18 raises `BASELINE` to `CONTRACT` and no scoped tier distinguishes a document until a later one rises above 18. A named link also satisfies the shared component's `additionalProperties`, since every link shape is a `LinkObject`. A sampler showing a links schema on its own now shows the generic shape; the operation example beside it shows the real links. The note that a response's `href` is absolute to the request host moved from every member's description to `RenderedLink`'s, or `LinkObject`'s when the server renders strings.
