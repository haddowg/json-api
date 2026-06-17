# Per-relation `dataOnlyWhenLoaded()` policy + storage-aware load-state seam

A relation may now opt into load-aware linkage via
`AbstractRelation::dataOnlyWhenLoaded()` (off by default; `emitsDataOnlyWhenLoaded()`
exposes it on `RelationInterface`): when its related value is not already in
memory, the relationship emits its `links` only and omits the `data` member
rather than triggering a lazy storage load purely to serialize identifiers. The
"is it loaded?" question is answered by an adapter-supplied
`RelationshipLoadStateInterface` (parent model + the `RelationInterface`, so the
adapter can read the backing column and cardinality) injected on the `Server` via
`withRelationshipLoadState()` and threaded down to relations through the existing
`SerializerResolverInterface` the same way the lazy resolver is — core ships no
implementation, so the default predicate is *always-loaded* and standalone core
keeps emitting data exactly as before. The gate is deliberately narrow: it defers
the data read (behind a callable, reusing `omitDataWhenNotIncluded()`) only when
the relation opted in, the predicate reports not-loaded, and the relation carries
links — an **included** relationship always re-emits data (include-wins, free from
the existing transform path) and a relation **without links** always emits data
(the validity guard, so omitting data can never produce an empty relationship
object).
