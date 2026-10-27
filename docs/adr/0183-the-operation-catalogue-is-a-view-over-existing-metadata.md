# The operation catalogue is a view over existing metadata

Clients asked which operations a caller can use. The agreed answer is an `OperationCatalog` and two helpers, not a capabilities endpoint. The catalogue walks the same operation enumerator the OpenAPI projection uses and reads scopes from the same `ScopeTable` enforcement checks, so the three can never list different operations or different scopes. It builds each `OperationDefinition` lazily from an integration-supplied closure returning a server's metadata, with nothing compiled and no configuration switch, because everything it needs is already held by the integration.

## Consequences

Core never interprets a security rule, so the rule and its `SecurityLevel` come from an `OperationSecuritySourceInterface`, and judging a caller against it is a `SecurityEvaluatorInterface`. The integrations implement both. `ScopeChecker` holds the scope comparison and `ScopeEnforcer` now delegates to it, so a check made from the catalogue and a refusal made during a request use one code path.

The Atomic Operations endpoint is listed last, with a `null` type, as the document lists it. A scope table that is declared but empty means no operation needs a scope; one missing an operation throws, as the projection does.
