# Custom constraints are typed value objects, not an opaque escape hatch

The vocabulary used to ship a `Custom` constraint — an opaque escape hatch
carrying a string `$id` and an arbitrary `mixed $payload` — for rules the core
doesn't model. It was stringly-typed on both sides: the resource constructed
`new Custom('email.strict', true)` and an adapter matched the `$id` against a
registry and reinterpreted the payload. The one core use, `Email::strict()`,
showed the smell — "strict" is a boolean property of email validation, not a
separate rule with its own identifier. So `Custom` is removed. Strictness is now
typed config on the constraint it belongs to (`EmailFormat::$strict`, set by
`Str::email(strict: true)` / `Email::strict()`), and a rule the built-in
vocabulary doesn't cover is expressed as a bespoke `ConstraintInterface` value
object carrying its own typed config.

`AbstractField::constrain(ConstraintInterface ...)` is the public, typed
attachment point for those bespoke constraints (the constraint helpers already
cover the built-ins; `constrain()` opens the set without subclassing). An adapter
translates a custom constraint by matching on its **class** rather than a string
id, and the JSON Schema compiler skips any constraint it doesn't recognise — so
the constraint *is* the contract end to end, with no parallel id registry to keep
in sync.
