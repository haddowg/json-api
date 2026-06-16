# Field hydration carries the create/update context

`FieldInterface::hydrate()` gained a trailing `bool $creating` parameter so a composite
field — chiefly `Map` — can gate its **read-only children** the same way the resource
gates top-level fields. Without it, a `Map` child declared `readOnly()` was still
writable through the nested object: the resource's `hydrateAttributes()` gates only the
top-level field (the whole `Map`), and `Map::hydrate()` had no operation context with
which to consult each child's `isReadOnly($creating)`. A field the author marked
read-only being silently writable violates the library's own write-gating contract (it
is *not* an over-posting hole — the declared-child loop still blocks undeclared keys).

This is a public-API signature change, which is why it lands **before** the 1.0 freeze:
adding a parameter to a frozen interface method afterwards is a breaking change. `Map`
now skips read-only children; the leaf `AbstractField`/`AbstractRelation` accept and
ignore the context (they don't recurse).
