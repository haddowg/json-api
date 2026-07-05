# A core `SchemaValueValidator` validates a value against a `Schema` with opis

- **Status:** accepted

Core gains a `SchemaValueValidator` that validates a single value against a `Schema`
(a JSON Schema 2020-12 node) with opis, returning one `422` `Error` per leaf
violation, each pointer being a caller-supplied prefix (`/data/attributes/<field>`)
plus the opis instance pointer.

**Why.** The `Shape` composite-schema constraint (ADR 0120) carries *raw JSON Schema*
members (`oneOf`/`anyOf`/`allOf`), which a host validator — Symfony `AtLeastOneOf`,
Laravel rules — cannot translate to a native rule. Its only validator is opis. Because
that execution is **framework-agnostic** (opis, plus core's own `Schema`/`Error`
types), the logic belongs in core rather than being re-implemented in both the Symfony
bundle and the Laravel package: each integration compiles a `Shape`-constrained field's
schema and calls this one validator, folding the returned `Error`s into its `422`
response. This is the opis counterpart to the *structural* document `DocumentValidator`,
applied to a *value* fragment.

## Consequences

opis is a `suggest` dependency, so a caller constructs `SchemaValueValidator` only when
`opis/json-schema` is installed (exactly as `DocumentValidator` is wired) and null-wires
it otherwise — a `Shape` then documents its OpenAPI shape but is not value-validated,
the same optional-linter posture opis already has. The validator returns core `Error`s
(status `422`) so an integration needs no error-mapping of its own; a caller wanting a
different status or code remaps. It is deliberately general (any value against any
`Schema`), so it also serves future raw-schema constraints, not only `Shape`.
