# Relationship `self` / `related` links by convention

A relationship object now emits the spec's recommended
`links.self` (`{baseUri}/{parentType}/{parentId}/relationships/{uriFieldName}`)
and `links.related` (`{baseUri}/{parentType}/{parentId}/{uriFieldName}`) by
default, with a per-relation `AbstractRelation::withoutLinks()` opt-out
(`includesLinks()` exposes the policy on `RelationInterface`). These are built in
the **transformer** — the only layer that knows the owning resource's type + id
(via the parent serializer's `getType()`/`getId()`) and the server's base URI,
which is now threaded through `ResourceTransformation` — because the relation
field has no access to the parent identity at build time; the field contributes
only the policy and its `uriFieldName()`. An explicit `setLinks()` still wins, and
links are omitted gracefully when the parent id is unresolvable (e.g. a
not-yet-persisted resource) rather than emitting a malformed URL.
