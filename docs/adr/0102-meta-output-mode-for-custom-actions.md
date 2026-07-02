# A custom action declares its success-output mode; a meta-only output projects a shared meta-document

An action's success response was inferred from `outputType()` alone — a type ⇒ a
`200` with that type's document, `null` ⇒ a `204`. That left a meta-only result
(an action that computes and returns a top-level `meta` with no primary resource,
the common shape for a report/summary action) undeclarable: it could only be
projected as a `204`, which contradicts the body it actually returns.

`ActionMetadataInterface` now carries an explicit `outputMode(): ActionOutputMode`
(`Document` / `Meta` / `None`) as the discriminator the projector switches on:
`Document` → a `200` with the output type's document schema, `Meta` → a `200` with
a shared `MetaDocument` component (emitted once, only when some action outputs
meta, and seeded before the enum collector so a clashing enum short name is
disambiguated rather than overwritten), `None` → a `204`. `outputType()` is retained
and read only in `Document` mode (a `Document` action with no output type is now a
guarded `LogicException` rather than a silent `204`). This is a pre-1.0 breaking
addition to the metadata contract.
