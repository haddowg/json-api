# `Shape` composite-schema constraint

- **Status:** accepted

A `Shape` constraint composes a set of member `Schema` fragments as `oneOf` /
`anyOf` / `allOf` (with an optional `discriminator` for a `oneOf`) and contributes
them to the owning field's schema through the self-describing `ProvidesJsonSchema`
seam (ADR 0114). Attached to a JSON-object field (`ArrayHash`) with `constrain()`, it
turns a pass-through value into a documented, validatable composite — the value
validates against the composed schema (the opis body validator natively; each
framework adapter maps the combinator to its native rules).

**Why.** `Obj` and `OneOf` (ADRs 0118–0119) own the *constructive* composites — they
read/write typed children, so a variant casts and maps to columns. The remaining need
is the *assertional* one: a value that is "one of / any of / all of these shapes"
which the server only wants to **document and validate**, storing it opaquely (with
any bespoke construction handled by the field's `serializeUsing`/`fillUsing` hooks).
That is squarely the constraint-schema seam's job — no new field type, and the full
`anyOf`/`allOf` vocabulary (which has no constructive field-type equivalent) comes for
free. Members are **raw `Schema` fragments**, so mixed-type unions, nested composites
and `$ref`s are all expressible without a bespoke member DSL.

## Consequences

The combinator is *added* to the field's accumulated schema per the
`ProvidesJsonSchema` contract, so `Shape` is attached to a field whose base type is
compatible with its members — an object field (`ArrayHash`) for object variants, where
the redundant `type: object` alongside an object-only `oneOf` is harmless; the
combinator is the authoritative shape. A `Shape` overlaps `OneOf` for `oneOf`
deliberately: `OneOf` when you need per-variant hydration / column mapping / per-child
pointers, `Shape` when you only need the value documented and validated. Semantic
validation of the combinator is the opis linter (structural) plus each framework
adapter's translator (the Symfony/Laravel bridges map `oneOf`/`anyOf`/`allOf` to their
native combinator rules); core carries only the metadata and the schema.
