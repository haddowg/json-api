# Request-aware restrictions keep the static schema a superset

`hidden()`, `readOnly…()`, `writeOnly()`, `cannotReplace/Add/Remove()` and `cannotBeIncluded()` accept a closure that returns `true` when the restriction applies, receiving the model first and the request second (write gates receive only the request, since a create has no model). The static getters report the permissive value for a closure-declared restriction, so the request-independent consumers (OpenAPI, JSON Schema, routes) describe the superset, and request-aware `…For()` resolvers make the runtime decision.

## Consequences

The read schema's `required` lists only the members guaranteed present, so a code generator marks a conditionally visible member optional; the standard `required` keyword was chosen over a bespoke `x-` marker so every OpenAPI tool understands it.
