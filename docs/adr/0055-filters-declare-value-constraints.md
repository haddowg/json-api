# Filters declare value constraints validated before the data layer

A filter was metadata-only (key, column, operator, deserialize, default) and
carried no value type, so a mistyped value — `filter[year]=banana` on an integer
column — flowed straight to the data layer, where the best case was a silent
non-match (the in-memory handler, and a loosely-typed database such as sqlite)
and the worst case a crash (a strict driver such as Postgres raising a PDO type
error — a `500`). Either way the client got no useful signal. We let a filter
**declare** value constraints — `FilterInterface` gains
`constraints(): list<ConstraintInterface>` (default `[]`), and the value-carrying
built-ins (`Where`, `WhereIn`, `WhereNotIn`, `WhereIdIn`, `WhereIdNotIn`) gain a
fluent `constrain(...)` plus the type shortcuts `numeric()` / `integer()` /
`uuid(?int)` / `boolean()` / `pattern()` via a shared `HasValueConstraints` trait
— so a framework adapter can validate a client-supplied value *before* the filter
reaches the data layer and reject a bad one cleanly.

The shortcuts **reuse the existing core constraint vocabulary** (`Pattern`,
`UuidFormat`, …) and mirror the `Id` field's `uuid()` / `numeric()` / `pattern()`
exactly, so a filter value is validated through the same constraint→native-rule
bridge an adapter already runs for attribute fields — no second validation
mechanism. The builders are **immutable withers** (the filter VOs are
`final readonly`; `Where::make()->numeric()` returns a new instance), matching the
existing `singular()` / `default()` / `deserializeUsing()` refinements; each host
filter supplies a `withConstraints()` because it alone knows its constructor. The
presence-only filters (`WhereNull`, `WhereNotNull`, `WhereHas`, `WhereDoesntHave`)
return `[]` — they ignore their request value, so there is nothing to validate.

Core also declares the `FilterValueInvalid` exception so any consumer (an
in-memory handler, a core path) can throw it; an adapter populates it from the
translated-constraint violations. It is a **`400`** with `source.parameter` on
`filter[<key>]` — a bad query *parameter*, modelled on `FilterParamUnrecognized`
— deliberately **not** a `422`, which is reserved for document *semantic* errors
located by `source.pointer`. It renders one `Error` per violation message. Like
every other constraint these are metadata only: core never executes them, and a
filter that declares none behaves exactly as before. Validating the value
pre-provider makes the check provider-agnostic — every adapter gets the same
deliberate `400` with `source.parameter` rather than the silent non-match an
unvalidated value would yield (or, on a strict driver, the downstream `500`).
