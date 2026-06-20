# Parent-aware identifier meta on relations

A resource identifier object MAY carry `meta` (`{type, id, meta}`), but the only
source core had for it was the related resource's own `getMeta()` — which sees just
the related object, so it renders identically wherever that resource appears and
cannot describe the *link* (the role a to-many member plays for this parent, when
the association was formed). That information belongs to the owning relation, not the
related resource, and the related resource's `getMeta()` has no access to the parent.

`AbstractRelation::identifierMeta(\Closure $resolver)` is the parent-aware hook: the
resolver receives `(parent, related, request)` and returns the meta to attach to each
resource identifier the relation renders in its linkage — every member of a to-many,
a to-one's single identifier, and the `/relationships/{name}` endpoint. Each build
path (`buildToOne`/`buildToMany` and the two polymorphic builders) binds it to the
parent + request and hands it to the built relationship via
`AbstractRelationship::withIdentifierMeta()`; `transformResourceIdentifier()` then
merges the resolver's result onto the identifier, the resolver winning on a top-level
key collision with the related resource's own meta (which may include a
`belongsToMany` pivot's `meta.pivot`). An empty contribution emits no `meta`. It is
linkage-only — the related resource object rendered into `included` is untouched.

Additive on the public surface — the `identifierMeta()` builder plus the internal
`AbstractRelationship::withIdentifierMeta()`, no signature change to any existing
method, and byte-identical output until a relation opts in — carried as `feat!` per
the repo's pre-1.0 convention (a minor bump). Distinct from the relationship-object
`meta` (`relationshipMeta()`, e.g. countable `total`) and from pivot meta (a
`getMeta()` decorator) — both keep their existing paths.
