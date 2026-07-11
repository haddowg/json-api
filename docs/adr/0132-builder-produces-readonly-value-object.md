# Field and filter builders produce a separate readonly value object

Status: accepted

_Realized across all four families: string and the remaining attribute types, the
filters, and the relations — the fat `AbstractField` / `AbstractRelation` /
`AbstractAttribute` bases are gone, replaced by the `AbstractFieldBuilder` /
`AbstractFieldValue` (and their relation subclasses) pair._

## Context

Every fluent field and filter type was simultaneously a **mutable builder** and
the **value object** the engine consumed. `Str::make('title')` returned a `Str`
that carried both the authoring surface (`->maxLength()`, `->readOnly()`,
`->when()`) and the consumption surface (`->serialize()`, `->isReadOnly()`,
`->constraints()`) on one class. Authoring autocomplete was polluted with
accessor methods and vice-versa, and "immutable value object" (see
[ADR 0003](0003-immutable-value-objects-with-carve-outs.md)) was untrue for the
one family that most looked like data.

## Decision

Split each fluent type into a mutable **builder** and the `final readonly`
**value object** it produces:

- **`make()` returns a builder.** `Str::make()` returns a `StrBuilder`; the value
  object keeps the plain class name (`Str`, `Where`, `Integer`) and its public
  props / `instanceof` identity, so consumers are unchanged. A type with no fluent
  surface (a presence-only filter, `SortByField`) returns its value object from
  `make()` directly.
- **`build()` freezes the builder into the value object.** Pure and idempotent,
  behind `FieldBuilderInterface { build(): FieldInterface }` /
  `FilterBuilderInterface { build(): FilterInterface }`. The builder holds config
  and the fluent methods; the value object holds the consumption behaviour (the
  value casts, the composite serialize/hydrate). No logic is duplicated across the
  two trees, and the value-object tree keeps `instanceof` depth only where a
  consumer discriminates on it — it is not a 1:1 mirror of the builder tree.
- **One build seam.** `fields()` (and `filters()`) may return builders or
  already-built value objects; `AbstractResource::allFields()` normalizes the list
  (`instanceof …BuilderInterface ? ->build() : $x`) and is the single public
  accessor every consumer reads through — inside the resource, the validation
  `SchemaCompiler`, and the framework adapters. A builder never leaks past the
  declaration boundary.
- **Sugar collapses to a builder facade with no value object of its own.** The
  string-format presets (`Email`, `Url`, `Uuid`, `Slug`, `Ip`) become
  `StrBuilder` facades that preset a format constraint and build a plain `Str`;
  nothing does `instanceof Email`, so the identity was never load-bearing. (The
  filter side's nine thin `Where` subclasses collapse the same way — see the
  filter phase.)

## Consequences

- **One authoring break:** the `when()` / `onCreate()` / `onUpdate()` closures now
  receive the **builder**, not the value object, so a type hint changes from e.g.
  `\Closure(Str $field)` to `\Closure(StrBuilder $field)` (this keeps
  type-specific helpers like `minLength()` visible inside the closure). Returning
  array literals from `fields()` is unaffected.
- Adapters and any custom consumer read built fields through `allFields()` rather
  than the raw `fields()` declaration list.
- Guards stay at chain-time (they fail at the offending setter); `build()` does not
  re-validate.
- The deeper segregation of the wide `RelationInterface` into runtime vs
  OpenAPI-projection contracts is intentionally **not** part of this change; it is
  a potential post-1.0 refactor.
