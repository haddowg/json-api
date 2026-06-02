# JSON Schema validation is optional and dev-time

Validating documents against the JSON:API JSON Schema is opt-in and backed by
`opis/json-schema` declared as `require-dev` plus `suggest` — never a hard
`require`. The validating middleware are added only on dev/CI servers, and the
injected validator makes wiring fail fast if the optional dependency is absent.

This keeps the production dependency footprint and per-request cost minimal for
the common case, while still offering strict structural validation (including
per-resource compiled schemas and profile fragments) wherever a consumer wants it.
