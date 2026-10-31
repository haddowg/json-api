# Operation scopes are enforced in core and projected from the same table

OAuth scopes must be checked identically on every adapter and on the include tree and atomic batch as well as the endpoint, so core resolves a `ScopeTable` once per server and `ScopeEnforcer` checks it before serving hooks and handlers, refusing with `403 INSUFFICIENT_SCOPE` and an RFC 6750 challenge. The projector reads the same table, so each operation's documented `security` names exactly the scopes enforcement checks. Where the design left a choice, the stricter option was taken: a missing table entry is resolved live rather than skipped, since a stale cache must never open an endpoint, and a public operation needs no scope, so a public related read whose types need scopes fails the build.

## Consequences

A relation reaching an unregistered type asks the resolver for that type's read scopes by name rather than failing.
