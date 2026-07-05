# `Obj` typed-object field + a self-describing field-schema seam

- **Status:** accepted

A new `Obj` attribute type models a **typed nested object stored in a single backing
value** (one JSON column / array property), with declared child fields addressing keys
*inside* that value — the single-value sibling of `Map` (which spreads its children
across separate flat columns). `Obj` serializes/hydrates its children against the
nested value (per-child merge on a partial `PATCH`; an explicit `null` clears it) and
carries per-child validation and `/data/attributes/<obj>/<child>` violation pointers.

Its schema is emitted through a new `ProvidesFieldSchema` seam: a field describes its
own OpenAPI base node and `SchemaProjector::projectField()` consults it before the
built-in type switch, then layers the common post-processing (constraints, nullable,
description, example) on top.

**Why.** The type model had scalars, a homogeneous list, an open map (`ArrayHash`) and
a column-spread object (`Map`), but no *typed object in one document* — the natural
storage for a structured attribute (an address, a settings blob) and the constructive
building block for the discriminated union that follows. Rather than grow the
projector's closed `instanceof` switch for `Obj` (and the union after it), the seam is
the field-level twin of the self-describing patterns already adopted for constraints
(ADR 0114) and filters (ADR 0115): a composite type owns its schema shape, and an
application can introduce its own composite field type and have it documented.

## Consequences

`Obj`'s children read/write within the nested value (they receive that value, not the
domain object, as their subject), so their casts round-trip cleanly in memory; a
storage adapter that persists the object to a typed JSON column owns the column↔array
mapping (or the author uses `fillUsing`/`serializeUsing`). Semantic validation of the
children cascades in each framework's validator bridge exactly as `Map`'s does — the
bridge walks `Obj::children()` the same way. `SchemaProjector::isRequired()` became
public so a self-describing composite can populate its object `required` from its
children as the built-in projection does; `Map` keeps its existing built-in branch
(it can migrate to the seam later with no output change).
