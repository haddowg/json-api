# An identified MorphTo renders linkage from its stored pair and is never load-aware

`identifiedBy()` lets a `MorphTo` read its `[type, id]` pair off the parent, so linkage renders without loading the target (one query per parent otherwise), and the target is read only when included. Because the pair never needs a load, an identified relation ignores the load-state predicate and always emits `data`. The pair is authoritative: an included target renders with the serializer of the pair's type, not one resolved from the object.

## Considered options

- Keep consulting the load-state predicate: it answers whether the target is loaded, which says nothing about the pair.
- Core resolving the target from the pair: core has no store; the host batches the pairs and supplies targets out of band.
