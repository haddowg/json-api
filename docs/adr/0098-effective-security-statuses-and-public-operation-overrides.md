# Project `401` from effective security, and support per-operation public overrides

The `401` advertising added in ADR 0097 keyed on the *per-operation* declarative
security (`securedOperations()`) only. But an operation can also be secured by the
**document-level default** (`defaultSecurity()`): in OAS, a document `security`
applies to every operation that does not override it. So an operation secured *only*
by the default carried the requirement but not the `401` response — and there was no
way to mark a single operation **public** when a document default applies.

The projector now resolves each operation's **effective** security and derives both
the per-operation `security` field and the `401` from it:

- **Effective security** = the per-operation requirement when set, else the document
  default. `401` is advertised iff the effective requirement is non-empty. So an
  operation that merely inherits a non-empty document default now correctly advertises
  `401` (the firewall returns it to an unauthenticated caller) — the firewall-protected
  API, configured only with a document default, is now fully reflected.
- **Public override.** A new `TypeMetadataInterface::publicOperations()` lists
  operations declared public. The projector emits the OAS operation-level
  `security: []` (the "no auth" override) for them and **no** `401`, regardless of the
  document default. `securedOperations()` and `publicOperations()` are disjoint; an
  operation in neither inherits the default.

This is the projection half of a host's per-operation security declaration: a host
can mark an operation secured (per-op requirement + `401`), public (`security: []`,
no `401`), or leave it to inherit the document default. The `Operation` VO already
serialized `security: []` distinctly from an omitted field; only its docblock was
corrected. `publicOperations()` is the contract addition every `TypeMetadataInterface`
implementer supplies.
