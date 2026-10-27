# Operation scopes are enforced in core from a table resolved once per server

A token's OAuth scopes have to be checked the same way on every adapter, and on the include tree and the atomic batch as much as the endpoint, so core owns the check. An application-supplied resolver, overridden by metadata, is asked once per operation by `ScopeTableBuilder`. `ScopeEnforcer` checks the resolved table after query validation and before the serving hooks and handler, and refuses with a `403 INSUFFICIENT_SCOPE` that carries the RFC 6750 challenge through a new `CarriesResponseHeadersInterface`. The table holds only strings, so an adapter can cache it, and a missing entry is resolved live with a warning rather than skipped, because a stale cache must never open an endpoint.

## Consequences

Where the agreed design left a choice open, the stricter option was taken:

- A related read needs the read scopes of the types it returns even when the endpoint itself is public. A public relation cannot leak a type the caller may not read. ADR 0182 then made such a public related read fail the build instead.
- Public means declared public: listed in `publicOperations()`, a relation security of `false`, or a relation endpoint inheriting a public parent. A server whose document security is empty is not treated as public everywhere.
- An override key must name an operation the type exposes, except `fetchOne` and `fetchCollection`, which every type has in the table because include hops are checked against them.
- A scope must be a token a `WWW-Authenticate` header can carry: printable ASCII with no space, double quote or backslash.
- A relation reaching a type the server metadata does not describe fails the build. During a request the same type is unresolvable, so the include walk stops there with a debug log and the render drops what the caller may not read.
- A refused include names the path up to the hop that failed, not the whole requested path.
- The render-time drop applies to every included resource. An explicit path has already been checked, so in practice it only removes default includes and resources past an unresolvable hop.
- A relation the metadata does not know, resolved live, gets only its own scopes, since its related types are unknown. The warning says the table is stale.

`INSUFFICIENT_SCOPE` is gated on a new `ErrorFeature::OperationScopes`, which no document offers until the projection describes operation scopes, so every generated document is unchanged.
