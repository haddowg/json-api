# Write-request `relationships` are typed per settable relation

A create/update request document (and the atomic `add`/`update` resource shapes) now
project a **typed** `relationships` property — a `{type: object, properties}` whose keys
are the relations settable in that write, each `$ref`-ing the same per-relation
`<Type><Rel>Relationship` component the read resource object uses — instead of the
opaque `relationships: {type: object}` placeholder.

Previously the write side advertised only a bare object, so a client (or a code
generator) could not see which relationships a write accepts or the linkage shape each
takes; the read side already typed them (`withRelationshipsProperty`). Reusing the read
relationship-object component is correct for writes: its `data` is the writable linkage,
and its optional `meta` is exactly where a `belongsToMany` pivot write rides.

Settability follows the runtime: a **create** may set any declared relation's initial
linkage, so it lists them all; an **update** replaces an existing association, so it
lists only relations whose replacement is permitted
(`RelationMetadataInterface::allowsReplace()` — an unconditionally locked relation is
omitted, matching the OpenAPI-as-superset rule where a conditionally locked one is still
advertised). When a write exposes no settable relation the `relationships` property is
omitted entirely. Taken before the 1.0 freeze.
