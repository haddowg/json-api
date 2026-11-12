# Each core error code is offered only by the operations whose runtime raises it

This refines [ADR 0121](0121-a-feature-gated-code-is-offered-only-by-the-operations-that-can-raise-it.md). Scoping by feature still offered codes where they cannot arise, such as `CLIENT_GENERATED_ID_NOT_SUPPORTED` on relationship mutations, which carry no resource id, and the `sort`, `filter` and `include` codes on deletes, which never read those parameters. So each of core's codes now follows a coverage rule in one per-code map, `OpenApi\ErrorCoverage`, built from the same `OperationFacts` the operation projector reads, with the feature gate still the outer filter and a declared scope still overriding. A rule covers what the Symfony and Laravel adapters and core's own PSR-15 stack can raise, a code no operation can raise is left out, and where the metadata cannot tell, as with a `readOnly(when:)` predicate, the rule offers the code, because a missing code hurts a client more than a spare one.

## Considered options

- Keeping a parallel per-code mechanism beside the feature arms: two places would decide one code's operations. The feature arms became rules in the map instead, and `ErrorFeature` only gates.
- Offering a parameter's codes only where the document advertises the parameter: the codes exist to refuse a parameter an operation does not take, such as `withCount` naming an uncountable relation on a type with none, so a code follows where the runtime reads the parameter, not where its values are listed.
- Grouping shapes by the operations a code reaches: shapes would merge, split and be renamed as operations change, so codes group by rule, as they grouped by feature.

## Consequences

A rule that covers every operation keeps its codes in the shared shapes. Nearly every other operation now answers `400` and `404` with an operation error document of its own, and a status no operation answers from the shared responses loses its shared component.
