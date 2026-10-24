# A nested object's read `required` lists the children it always renders

The read attributes object listed every always-present attribute in `required`, but a `Map`, `Obj` or `OneOf` inside it listed none, so a generated client made every nested child optional even though the runtime renders each one on every response. A nested object's read schema now follows the top-level rule: every child is required except a write-only one (also dropped from the read `properties`, since it is never rendered), a `hidden(when:)` or `writeOnly(when:)` one, and every child of a parent whose `serializeUsing()` / `extractUsing()` hook returns a shape the author controls. The rule recurses, presence stays independent of `null`, and the create and update shapes are unchanged.

## Considered options

- Mark the children of a hook-rendered parent required anyway. The hook can return any shape, so nothing about its keys is known; understating costs a client one presence check, overstating breaks it.
- Apply the same rule to a `belongsToMany` relation's `meta.pivot` object. That schema is one component shared by the read linkage and the relationship-mutation request bodies, so listing every rendered pivot field in its `required` would also demand read-only fields from every request. It keeps its create-required list until the two are split.
