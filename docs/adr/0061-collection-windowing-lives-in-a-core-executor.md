# Collection windowing lives in a storage-agnostic core executor

The bundle's read providers each hand-rolled the same window/count/count-free
"tail" — no-window passthrough, an `OffsetWindow` guard, a counted page, and a
limit+1 count-free page (core ADR 0057) — in four places. We consolidate that tail
into a single `haddowg\JsonApi\Collection\WindowExecutor` and move the
`CollectionResult` value object alongside it into a new `haddowg\JsonApi\Collection`
namespace, because these are storage-agnostic read contracts that sharpen the v1
public API: the executor references only core/PHP types and takes the store-specific
work (materialize / count / page / probe) as closures, so every data layer — the
Doctrine push-down, the in-memory witness, a future custom provider — gets identical
branch behaviour, and the same seam is where the cursor (keyset) window strategy
will plug in without touching any provider.
