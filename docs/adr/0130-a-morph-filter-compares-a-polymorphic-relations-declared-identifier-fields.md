# A morph filter compares a polymorphic relation's declared identifier fields

`WhereMorph` filters by a polymorphic relation's stored identifier, sent as a resource identifier, `filter[subject][type]` with an optional `filter[subject][id]` list, so it works on a linkage-only relation with no related endpoint or include. A data layer can compare only fields it can name in a query, so a `MorphTo` declares them with `identifiedByColumns(type:, id:, typeMap:)`, mirroring a `MorphToMany`'s `identifiedByRows()`, and the linkage renders from the same two fields; a closure from `identifiedBy()` keeps working for everything else, but Doctrine and the in-memory reference refuse a `WhereMorph` over one, while Eloquent reads the model's own `morphTo` relation and its morph map. `WhereSet` sits beside it as a value filter, `true` for IS NOT NULL and `false` for IS NULL with both meaning either, because `WhereNull` and `WhereNotNull` are presence filters that can neither take a default nor let a client ask for both.

## Considered Options

- Keying the filter by type, `filter[subject][rfps]=812`: it reads naturally for one type, but its keys are data, so it needs a family per relation and no longer mirrors the identifier a client already holds.
- Naming the columns on the filter rather than the relation: Eloquent would ignore them, every vocabulary using the filter would repeat them, and the linkage would still need its own closure.
- Giving `WhereNull` a value and a default: it would change what an existing presence filter means for every client already sending it.
