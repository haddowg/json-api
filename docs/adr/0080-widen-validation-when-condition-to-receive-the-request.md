# Widen the validation `when()` condition to receive the request

The field-level `when($condition, $builder)` constraint conditionally applies its
wrapped rules; its `$condition` was `\Closure(mixed $value): bool`. We widened the
declared signature to `\Closure(mixed $value, ?JsonApiRequestInterface $request): bool`
— **value first**, request second and nullable — so an author can make a
validation rule conditional on the caller (e.g. "required only for admins")
alongside the new request-aware visibility/authz predicates, while every existing
`fn($value)` closure keeps binding unchanged (PHP ignores the extra parameter) and
a context that carries no request (entity-level, filter-side) passes `null`.

`When` is **metadata only** in core — the `SchemaCompiler` deliberately skips it
(the condition is opaque PHP) and the bundle's `ConstraintTranslator` is the sole
execution site. So this is a **documentation-only** widening in core (the `When`
VO and `AbstractField::when()` docblocks); the runtime change — capturing the
request into the `Callback` and invoking `$condition($value, $request)` — lives in
the bundle. Kept as its own ADR from 0079 because it widens a *different axis*
(validation-value gating) than the visibility/authz predicates, and the choice to
*widen `when()` rather than repurpose it* (value-first for backward compatibility)
is the decision worth recording.
