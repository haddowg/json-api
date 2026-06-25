# A relation declares its own security, overriding the parent's gate

A relationship's related/relationship endpoints (`GET /{type}/{id}/{rel}`,
`GET|PATCH|POST|DELETE /{type}/{id}/relationships/{rel}`) hang off a parent resource
and, until now, were authorized only by that parent's security — there was no way to
authorize a single relationship independently. But a relation often warrants its own
gate: a public resource with one privileged relationship, or a restricted resource
with one openly-readable relationship.

A relation now carries two optional security declarations, set by
`AbstractRelation::security(read:, mutate:)` and exposed on `RelationInterface`
(and mirrored on the OpenAPI `RelationMetadataInterface`):

- **`securityRead`** governs the related and relationship **read** endpoints.
- **`securityMutate`** governs the relationship **mutation** endpoints
  (`PATCH`/`POST`/`DELETE …/relationships/{rel}`).

Each value mirrors a resource's `security`: an authorization expression **string**
(enforced by the host + documented secured), the bool **`true`** (documented secured
only), **`false`** (documented public), or **`null`** (inherit the owning resource's
read/update security). Core only stores and exposes these; the host evaluates the
expression and the OpenAPI projector reads them.

**Semantics = override, not intersect.** A relation's declared read/mutate security
*replaces* the parent's gate for that relation's endpoints, so a relation can be
**more *or* less** permissive than the resource it hangs off; `null` (the default)
falls back to the parent, preserving today's behaviour. The projector reflects this:
`false` → `security: []` (the OAS no-auth override) with no `401`; a string/`true` →
the configured document requirement; `null` → the parent operation's projected
security. Enforcement of the expression is the host's job (the Symfony bundle wires
new read events + the existing mutation gate to evaluate it).
