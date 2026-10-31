# One enumerator decides which operations a type exposes; the catalogue is a view over it

An internal `OperationEnumerator` is the single list of a type's operations (allow-list, endpoint exposure, write-only and update gating), each named by a public `OperationKey`, and the operation projector, the example projector, scope enforcement and the `OperationCatalog` all walk it. The catalogue that tells clients which operations a caller can use is therefore a view over existing metadata and the enforced `ScopeTable`, not a capabilities endpoint, so the document, enforcement and catalogue can never disagree.

## Consequences

Core never interprets a security rule: the rule and judging a caller against it come from integration-implemented interfaces.
