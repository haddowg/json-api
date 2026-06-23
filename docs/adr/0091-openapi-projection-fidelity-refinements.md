# Project the OpenAPI doc to match real responses: gate `included`, type numeric filters, example slugs

Three places where the generated document advertised something a real response
never produces, or a shape no client would want.

**`included` is gated on includability.** Every resource / related document
envelope unconditionally described an `included` member. A type with no
includable relationship path rejects `?include` (and a related endpoint whose
relation exposes none does too), so its responses can never carry `included` —
advertising an always-absent member is noise. The single/collection envelopes
now take a `bool $includable` (`TypeMetadataInterface::includablePaths() !== []`)
and the to-one-related / polymorphic-related envelopes gate on the relation's
`relatedIncludablePaths()`; the member is described only when a response could
actually carry it.

**Numeric/boolean convenience filters document their JSON type, not their wire
pattern.** A query value arrives as a string, so `numeric()`/`integer()`/
`boolean()` validate it with a `Pattern` regex — correct for runtime, but the
projector emitted that regex as the parameter's `schema.pattern`, so
`filter[rating]` read as a string matching `^-?[0-9]+…$` (and a `Range`'s
`min`/`max` bounds the same). `Pattern` gained an **OpenAPI-only**
`?string $documentsAs` hint: when set the projector emits `type: <documentsAs>`
**instead of** `pattern` (a `pattern` is meaningless on a non-string schema);
runtime validators read only `->regex` and are unchanged. `numeric()`→`number`,
`integer()`→`integer`, `boolean()`→`boolean`; an author's raw `pattern($regex)`
still projects a string `pattern`. This generalises to any filter built on those
presets, including a `Range`'s bounds.

**A default slug carries a readable example.** A `Slug` (and `Str::slug()` with
the default shape) emits a `pattern`, from which a renderer synthesises a
gibberish example. `slug()` now presets `example: 'example-slug'` for the default
slug regex (a custom regex may not match it, so only the default form gets a
default), overridable by `->example(...)` and left untouched when the author
already declared one. The pattern stays — it is a useful, correct constraint.
