# Operation scopes are projected from the table that enforces them

A client reading the OpenAPI document needs to know which scopes to request, and a document that disagreed with enforcement would be worse than none. So server metadata hands the projector the same `ScopeTable` through `DeclaresOperationScopeTable`, and each operation's `security` names exactly the scopes enforcement checks. A document in which no operation needs a scope is unchanged, and one that has some carries a scoped contract tier, `SCOPES` at 16, and offers `INSUFFICIENT_SCOPE` in its error catalogue.

## Consequences

When a requirement in a scoped operation's security names the scope scheme, it gets the scopes and keeps its other members, and every other alternative stays. A credential the scope check never sees, such as a session cookie whose grant is `null`, really does still work, and the 1.17.0 rule that dropped those alternatives hid it. When no requirement names the scope scheme, the security is `[{<scope scheme>: [scopes]}]`, as before, since then nothing says which alternative carries the token. An app whose token is the OAuth token should therefore name the `oauth2` scheme in its default security rather than a separate `bearer` scheme. An operation that only inherited the document default gets the scoped result as its own `security`.

A public related read whose returned types need read scopes now fails the scope table build, as a public operation given scopes already did. ADR 0181 had it keep the union. The union is what the operation needs, and the rule is that a public operation needs no scope. Projecting the union on a public endpoint instead would have made a declared-public operation carry a requirement, an exception to that rule that every reader of the document would have to know about.

The scheme's flows list the catalogue's scopes after the configured ones, and a configured description wins. A required scope that neither declares is an `UndeclaredScope` diagnostic, which an export can fail on under a strict flag. A scope scheme that is not `oauth2` still carries the scopes and is reported as a `ScopeSchemeNotOAuth2` diagnostic only, since OpenAPI 3.1 allows scopes on any scheme. A scheme the server does not define throws.

An empty `ScopeTable` means the server uses no scopes. A non-empty table missing a projected operation throws, because it was built from other metadata.

A linkage-only relation can point at a type the server does not register, which ADR 0137 allows, so the scope table build no longer fails on one. The resolver is asked for that type's read scopes by name instead, through a new `OperationKey::$type` with `null` metadata, at build time and on the live path. Every key the builder hands the resolver now names its type.
