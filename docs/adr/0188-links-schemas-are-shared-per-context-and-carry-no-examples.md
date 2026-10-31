# Links schemas are shared per context and carry no examples

There is one links component per context (`ResourceLinks`, `DocumentLinks`, `CollectionLinks`, `RelationshipLinks`, `RelationshipDocumentLinks`, `RelatedDocumentLinks`), plus a `<Type>ResourceLinks` `allOf` only for a type with declared links. Per-type and per-relation links components repeated the same members and examples and made up a large share of a document, and operation examples already show each document's real links whole.

## Considered options

- Thin per-type wrappers around the shared components: names kept forever for no information.
- Listing only the members a relation's exposed endpoints allow: needs a component per relation shape.
