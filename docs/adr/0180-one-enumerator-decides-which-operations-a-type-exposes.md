# One enumerator decides which operations a type exposes, keyed by `OperationKey`

The operation projector, the example projector and the CRUD map behind a type's declared extensions each walked a type's operations themselves, with their own copy of the allow-list, endpoint exposure, write-only and update-gating rules. Scope enforcement, the scope projection and an operation catalogue are about to need the same list, so an internal `OperationEnumerator` now produces it once, and each entry names its operation with a public `OperationKey` of an `OperationKind`, the relation or action, the method and the action's `OperationSafety`. The key's name reuses the grammar `operationExamples` is already keyed by, so `OperationKey::fromName()` is the one parser for it and adapters can drop their own copies.

## Consequences

The enumerator stays `@internal`. Adapters get the parser and the key types now, and the public view over the list arrives with the operation catalogue.

`OperationKind` includes `Action` and `Atomic` from the start, though the enumerator lists no atomic operation, so a consumer that matches on it exhaustively is not broken when scopes add the atomic endpoint. An action's key name is `action.<path>.<method>` in lower case, unique within a type because ADR 0179 refuses one path at both scopes. `fromName()` parses only the CRUD, related and relationship names, since a name cannot carry an action's scope or safety. An action's `OperationSafety` is `null` when it declares no flag, the same condition under which it gets no `x-safety`.

The document does not move. An action that declares no method used to leave an empty path item behind and now leaves nothing, and when two relations or two actions share a path the later one owns the path, as it already did in the document.
