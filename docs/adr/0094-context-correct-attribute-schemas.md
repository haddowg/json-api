# Project read / create / update attributes in their own representation context

The OpenAPI attribute projection had only a `bool $creating`, and its sole
visibility filter excluded **write-only** fields from reads. Two things were
wrong:

- **Read-only fields leaked into write bodies.** A `readOnly()` field (e.g. a
  server-derived `slug`) appeared in the create schema as if it were writable —
  the projection never consulted `FieldInterface::isReadOnly()`.
- **The update body reused the read shape.** The update request (and the atomic
  update) `$ref`'d the read attributes, so they *included* read-only fields (not
  writable on a PATCH) and *excluded* write-only fields (which **are** writable on
  a PATCH). Context-dependent `readOnlyOnCreate()` / `readOnlyOnUpdate()` were
  ignored entirely.

There are really **three** representations, which cannot share one schema because
field visibility and the `required` set both differ. A new
`RepresentationContext` enum (`Read` / `Create` / `Update`) drives
`projectAttributes()`:

| context | excludes | `required` |
| --- | --- | --- |
| Read | write-only | none (a response) |
| Create | `isReadOnly(create)` | the create-required set |
| Update | `isReadOnly(update)` | none — a PATCH is partial |

Each type now emits three components — `<Type>Attributes` (read, resource
object), `<Type>CreateAttributes` (create request + atomic add) and
`<Type>UpdateAttributes` (update request + atomic update) — replacing the single
`<Type>WriteAttributes`.

`required` stays **create-only**, matching the runtime exactly: core's opis
`SchemaCompiler` and the bundle's Symfony `ResourceValidator` both relax a
required field to *optional-but-NotBlank* on update (a PATCH never requires a
member to be present), so the update schema's `required` is correctly empty —
`requiredOnUpdate()` constrains a *supplied* value, it does not force presence.
