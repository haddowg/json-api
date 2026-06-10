# Relationship-endpoint hydration lives in the resource, with core-thrown mutability gates

Relationship-endpoint mutations (`PATCH`/`POST`/`DELETE` on
`/{type}/{id}/relationships/{name}`) are hydrated by `AbstractResource`, which now
implements `UpdateRelationshipHydratorInterface::hydrateRelationship($name, $request,
$model)`: it resolves the relation via `relationNamed()` (throwing
`RelationshipNotExists`/404 when unknown), derives the mode from the HTTP verb
(`PATCH` → replace, `POST` → add, `DELETE` → remove), enforces cardinality
(add/remove are to-many only — a `POST`/`DELETE` on a to-one throws
`RelationshipTypeInappropriate`/400) and mutability, then applies the
storage-agnostic baseline (a scalar-column write via the relation's
replace/add/remove). A data-layer adapter overrides the apply to mutate the real
association.

Relations gained fluent `cannotReplace()` / `cannotRemove()` (both allowed by
default) plus `allowsReplace()` / `allowsRemove()` accessors, and a `Mode`
enum-driven `applyToMany()` with Add (append, deduplicated for set semantics) and
Remove (subtract) modes alongside the existing Replace. This finally **throws**
the long-defined-but-never-thrown `FullReplacementProhibited` (a `PATCH` replace,
or a to-one `data:null` clear, against a relation that `cannotReplace()` — and the
to-one clear against `cannotRemove()`) and `RemovalProhibited` (a `DELETE` remove,
or a to-one clear, against a relation that `cannotRemove()`).

These gates stay in **core**, not in framework routing, for two reasons: (a) core
is router-agnostic — it cannot assume an HTTP method is available to gate on; and
(b) the JSON:API Atomic Operations extension dispatches `op:remove` / `op:update`
with **no HTTP method to route on**, so a prohibited mutation can only ever surface
as a `403` throwable raised at the hydration seam, never as a route that is simply
not registered.
