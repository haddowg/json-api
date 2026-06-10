# Composition combinators: `Sequentially` and `AtLeastOneOf`

`Each` (all items) and `When` (conditional) were the only composition the
vocabulary offered. Two more pull their weight for real validation: `Sequentially`
applies a set of constraints to a value in order, stopping at the first failure
(so a cheap or prerequisite check guards an expensive or dependent one — validate
the format before the bound); `AtLeastOneOf` passes when a value satisfies any one
of several alternatives. Both mirror `Each`'s shape — a value object wrapping a
`list<ConstraintInterface>` plus a `Context` — and attach via the
`AbstractField::sequentially(...)` / `atLeastOneOf(...)` variadic builders.

Unlike `When`'s opaque closure, both round-trip to JSON Schema: `Sequentially`
merges its wrapped constraints into the field's own schema (all must ultimately
hold, so ordering is an execution-only concern the schema needn't model), and
`AtLeastOneOf` compiles to `anyOf` with one sub-schema per alternative. A framework
adapter maps them to its native equivalents (Symfony ships both constraints
directly).
