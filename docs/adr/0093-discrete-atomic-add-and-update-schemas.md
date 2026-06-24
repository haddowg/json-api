# Project discrete `AtomicAdd` / `AtomicUpdate` schemas, and add a `false` (never) schema

ADR 0092 modelled the atomic operation payload with one fused `<Type>AtomicWrite`
component. That conflated two genuinely different shapes and so could describe
neither precisely:

- **Attributes.** It used the create-context attributes (`<Type>WriteAttributes`,
  with their `required`), so the schema advertised that an atomic **update** must
  carry every required field — wrong for a partial `update`, where an absent
  member means "no change".
- **Identification.** It always offered `id`, even for a type that disallows
  client-generated ids — so it advertised a client `id` on an `add` that the
  server would reject.

The two shapes are now projected separately and referenced from the operation
`data` `anyOf`:

- **`<Type>AtomicAdd`** — `attributes` `$ref` `<Type>WriteAttributes` (create
  shape, with `required`). A client `id` is offered **only** where
  `allowsClientId()` is true; there `id`/`lid` are a titled three-mode `oneOf`
  (client id / local id / server-assigned). Where a client id is not allowed,
  `id` is **forbidden** (a `false` schema) and `lid` stays optional — exactly the
  standalone `<Type>CreateRequest` rule.
- **`<Type>AtomicUpdate`** — `attributes` `$ref` `<Type>Attributes` (the read /
  partial shape, no `required`, as `<Type>UpdateRequest` already uses). Target
  identification is a titled three-mode `oneOf` (by id / by lid / neither, when the
  operation targets via its `ref`/`href`).

This deepens the attribute-component reuse from ADR 0092: `<Type>Attributes` is
now shared by the resource object, the update request **and** the atomic update;
`<Type>WriteAttributes` by the create request **and** the atomic add.

To express "this member must be absent" directly, `Schema::never()` projects the
JSON Schema 2020-12 boolean **`false`** schema (it validates nothing, so as a
property value it forbids that property): `{"properties": {"id": false}}` reads as
"`id` must be absent". It replaces the `not: { anyOf: [...] }` form for the
"neither" arm and is reused to forbid `id` on a no-client-id add. The `false`
schema is a sub-schema only (a property value / list member), never a standalone
document node.
