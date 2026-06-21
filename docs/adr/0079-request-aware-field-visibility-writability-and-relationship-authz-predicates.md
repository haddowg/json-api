# Request-aware field visibility / writability / relationship-authz predicates

`hidden()`, `readOnly()`/`readOnlyOnCreate()`/`readOnlyOnUpdate()`, `writeOnly()`
(on `AbstractField`) and `cannotReplace()`/`cannotRemove()`/`cannotAdd()`/
`cannotBeIncluded()` (on `AbstractRelation`) each now accept an optional closure
that decides the restriction **per request** — the uniform convention being that
the closure returns `true` when the restriction applies (`hidden(fn => true)` ⇒
hidden; `cannotReplace(fn => true)` ⇒ cannot replace). This closes the Laravel-
parity cluster (per-caller field hiding / writability and relationship
authorization) without a security framework: read-hiding and relationship-authz
predicates receive `(mixed $model, JsonApiRequestInterface $request)` — **model
first**, uniform with every other author closure on a field/relation
(`serializeUsing`/`extractUsing`/`computedUsing` and the relation `identifierMeta`
resolver), so an author never swaps argument order between adjacent closures —
while the write-gating predicates (`readOnly`/`writeOnly`) receive only
`($request)` because a create has no persisted model yet. (An earlier iteration
ordered these `($request, $model)`; they were aligned to model-first at the v1
freeze for cross-closure consistency. The internal `*For(...)` resolver *methods*
stay `($request, $model)` — they are not author closures.)

The existing **static getters** (`isHidden()`, `isReadOnly(bool)`,
`isWriteOnly()`, `allowsReplace/Remove/Add()`, `isIncludable()`) are kept,
re-specified as *"unconditionally restricted"*, and a closure-declared field
reports the **permissive** value from them — so the request-independent
build-time / OpenAPI / JSON-Schema / `Map` / pivot paths see the **superset**
(a sometimes-hidden field still appears in the schema; a sometimes-prohibited verb
is still exposed). New request-aware `*For(...)` resolvers (`isHiddenFor`,
`isReadOnlyFor`, `isWriteOnlyFor`, `allowsReplaceFor`/`RemoveFor`/`AddFor`,
`isIncludableFor`) carry the runtime decision and are the gates the render /
hydrate / mutate / include sites consult; `IncludeControlsInterface::getNonIncludableRelationships()`
gained the `$request` argument (threaded through `ResourceTransformer`) so a
relation can be non-includable for one caller only. The `writeOnly`↔`readOnly`
contradiction guard was narrowed to fire **only** for the unconditional ×
unconditional case: a closure on either side defers the decision to request time,
where each resolver is individually coherent, so it must not throw.

This is a pre-1.0 breaking change (the `FieldInterface` / `RelationInterface` /
`IncludeControlsInterface` contracts grew methods); the bundle executes the new
resolvers at the request-aware sites.
