# Constraints self-describe their JSON Schema via `ProvidesJsonSchema`

- **Status:** accepted

A constraint's JSON Schema keyword now lives **on the constraint** — each
self-contained leaf constraint (`MinLength`, `Pattern`, the numeric/size bounds,
the `format`/`pattern`/`not` keywords, …) implements
`ProvidesJsonSchema::contribute(Schema): Schema` and returns the accumulated node
augmented with its own keyword. The two schema consumers — the OpenAPI
`SchemaProjector` and the body-validation `SchemaCompiler` — now **reduce over that
one method** instead of each carrying its own mirrored `instanceof` switch.

**Why.** The mapping was duplicated in two hand-maintained switches (`SchemaProjector`
for the published OpenAPI, `SchemaCompiler` for the opis request-validation schema)
that had to be kept in lockstep — the projector's docblock even said it "mirrors"
the compiler. Adding a constraint meant editing both, and the inbound-validation
schema and the published OpenAPI could silently drift. Moving the keyword onto the
constraint makes it a single source of truth and turns the mapping into an
**extension point**: an application (or a framework adapter's native-rule carrier)
can attach a constraint outside core's vocabulary with `constrain()` and have it
appear in *both* the OpenAPI document and the validation schema with no core change.

**Why on the constraint (and not, like validation execution, in a per-consumer
translator).** JSON Schema is **framework-neutral** — `MinLength(3)` *is*
`{"minLength": 3}` in every host — so the schema shape is an intrinsic property of
the constraint and belongs on it. Validation *execution* (`min:3` vs
`Assert\Length`) is host-specific and correctly stays in each framework adapter's
translator. Schema-on-the-class, execution-in-a-translator is the deliberate,
principled asymmetry.

## Considered and rejected

- **A `Schema::merge()` and a fragment-returning `toJsonSchema(): Schema`.** The
  immutable `Schema` VO has withers but no merge, and the transform shape
  (`contribute(Schema): Schema`, folded left over a field's constraints) needs
  none — a leaf just calls a wither on the accumulator. It also lets a constraint
  choose to *wrap* (`allOf`/`anyOf`) rather than merge if it ever needs to, without
  a central merge policy that must be universally correct.
- **Moving *every* constraint onto the interface.** Deliberately not. `In` (enum
  var-names / backed-enum component hoisting), the composites (`Each`/`Sequentially`/
  `AtLeastOneOf`), the `date-time` bounds (`Before`/`After`/`Between`, degraded to a
  prose note in OpenAPI but emitted as `formatMinimum`/`formatMaximum` for validation),
  and `When`/`CompareField` (no lossless keyword) produce **consumer-specific** output,
  so they keep their explicit per-consumer arms. `Pattern` implements the interface for
  its base `pattern`; the projector overlays the OpenAPI-only `documentsAs` type itself.
  The seam covers the self-contained leaves, which is where the duplication actually was.

  A composite is not itself `ProvidesJsonSchema` — its wrapper shape (`items`/`anyOf`),
  its create/update context filtering, and its projector-only state (the enum-component
  collector, the degradation notes) cannot ride a bare `contribute(Schema): Schema`, and
  it may wrap a non-leaf inner (a date bound, an `In`) whose schema is consumer-specific.
  But a composite **recurses through** the seam: it re-enters each consumer's
  `applyConstraint`, so a **leaf** inner — including a consumer-defined one attached with
  `constrain()` — still self-describes from the same single source of truth. So the
  keyword decision for a leaf is single-sourced whether it sits at the top level or
  nested inside a composite; only the composite's own structural plumbing (which differs
  because the projector builds `Schema` VOs and the compiler builds arrays) stays per
  consumer.

## Consequences

`SchemaCompiler` (namespace `Validation`) now depends on the `Schema` VO (namespace
`OpenApi`) to read each leaf's contributed keyword — an acceptable coupling, since
`Schema` is the neutral JSON-Schema currency both the projection and the compiler
speak. Output is unchanged: the full suite (including the projector and compiler
byte-for-byte fixtures) stays green.
