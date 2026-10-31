# Custom constraints are typed value objects, not an opaque escape hatch

A rule the built-in vocabulary does not cover is a bespoke `ConstraintInterface` value object with its own typed config, attached with `constrain()`; there is no stringly-typed `Custom(id, payload)` constraint. An adapter translates a custom constraint by matching its class and the JSON Schema compiler skips what it does not recognise, so the constraint is the contract end to end with no parallel id registry to keep in sync.
