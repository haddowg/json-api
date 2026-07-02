# Project conditional read visibility via the read schema's `required` array

Request-aware visibility predicates (`hidden(when:)` / `writeOnly(when:)`, ADR 0079/0080)
keep a field in the read (superset) OpenAPI schema while allowing it to be absent from
the wire for some requests. The read attributes component carried no `required` array,
so a consumer (and code generator) had no signal to distinguish a guaranteed-present
member from a conditionally-present one, and typed every read attribute as always
present.

The read attributes projection now populates `required` with the members guaranteed
present — every read attribute except one whose presence is request-conditional
(`FieldInterface::hasConditionalReadVisibility()`, true when a `hidden(when:)` or
`writeOnly(when:)` predicate is declared). We chose the standard JSON-Schema `required`
array over a bespoke `x-conditional` marker so any OpenAPI-aware tool benefits without
understanding a custom extension; a code generator marks non-required read members
optional. `required` in a response schema means "always present," which is exactly the
superset guarantee.
