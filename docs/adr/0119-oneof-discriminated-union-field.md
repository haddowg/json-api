# `OneOf` discriminated-union field

- **Status:** accepted

A `OneOf` attribute models a **discriminated union**: the value is exactly one of a
set of named object shapes (`Obj`-shaped variants declared inline from their child
fields), selected by a **discriminator** property (default `type`). The whole object
lives in a single backing value with the discriminator stored alongside the active
variant's children; on hydrate the discriminator picks which variant's children run —
so a variant's typed children still cast, a variant can map to columns via its
children / `fillUsing`, and violations carry `/data/attributes/<field>/<child>`
pointers. It projects, through the `ProvidesFieldSchema` seam (ADR 0118), to OpenAPI
`oneOf` + a `discriminator` object, each branch carrying the discriminator as a
`const` (and, on create, in `required`).

**Why.** This is the constructive half of union modelling — a value that is genuinely
one of several *shapes* the server must read, write and document faithfully (content
blocks, typed events, polymorphic settings). Making the discriminator intrinsic (there
is no un-discriminated `OneOf`) keeps hydration deterministic: exactly one variant is
selected, so there is never an ambiguous "which shape do I transform through". A looser
"one of these shapes" that only needs documenting and validating (not hydrating per
variant) is a schema-bearing constraint on a pass-through field instead — the two are
complementary, not competing.

## Consequences

Variants are declared inline (`->variant('image', Url::make('src'), …)`) and stored
internally as `Obj`s, so the child-cascade validation and projection reuse `Obj`
wholesale. A partial `PATCH` that omits the discriminator merges onto the stored
variant; restating a *different* discriminator switches variant and starts fresh (stale
keys of the previous shape do not linger); an unknown/absent discriminator has no
variant to hydrate or render through, so the raw value is stored/echoed for the
validator to reject (hydration never validates). A nullable union appends a `null`
branch to its `oneOf` (there is no scalar `type` to widen), handled in the projector's
nullable step.
