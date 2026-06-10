# Cross-field comparison: the `CompareField` constraint

Every constraint so far validates a value against a fixed bound or set. Real
resources also need a value validated against **another field**: `endDate` after
`startDate`, `max` not below `min`, `passwordConfirm` equal to `password`.
`CompareField` declares that — `field` names the other attribute and a
{@see Comparison} operator reads `<this field> <operator> <field>`. It attaches
via `AbstractField::compareWith('startDate', Comparison::GreaterThan)`.

It is **not** round-tripped to JSON Schema (draft 2020-12 has no cross-property
comparison), so the compiler skips it and a framework adapter executes it — and,
unlike the per-value constraints, executes it at the **document** level, where the
sibling field's value is in scope (the adapter validates the whole `attributes`
object, not each field in isolation). Keeping the *declaration* in the core
vocabulary — rather than leaving cross-field rules to each adapter — means the
concept stays portable; only the execution is adapter-specific.
