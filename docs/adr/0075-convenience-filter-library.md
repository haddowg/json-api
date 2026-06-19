# Convenience filter library

A small set of **intent-named filter conveniences** —
`Contains`/`StartsWith`/`EndsWith`, `Numeric`/`GreaterThan`/`GreaterThanOrEqual`/
`LessThan`/`LessThanOrEqual`, and `Boolean` — let authors declare *what they mean*
(`Contains::make('name')`, `GreaterThan::make('age')`) rather than hand-wiring a
`Where` operator, a value deserializer and a value constraint by hand. Each
convenience presets, from a single declaration: the comparison **operator**, a
typed **deserializer** (numeric → `int`/`float`, boolean → `bool`), the matching
**value constraint** (`numeric()`/`boolean()`), and a human **description** — so
value coercion, validation, and the OpenAPI value schema all line up.

They are **thin `Where` subclasses**, dispatched by the handlers' existing
`instanceof Where` arm with **zero handler change** (the agreed design, §4-A): the
only new operators are `starts`/`ends` (a `match` arm in `ArrayFilterHandler::compare()`
mirroring `like`'s ASCII case-fold; the Doctrine arms are a follow-up slice).

## `Where` is no longer `final`

To make the conveniences subclasses (so a single `instanceof Where` arm catches
all of them and the OpenAPI projector can tell a `Contains` from a hand-rolled
`Where`), `Where` drops `final` (it stays `readonly`) and its `make()`/withers
construct via `new static(...)` rather than `new self(...)`. Without the
`self`→`static` change a fluent refinement (`GreaterThan::make('age')->describedAs(…)`)
would silently downcast to a bare `Where` and lose the preset operator,
deserializer and constraint. `Where` is marked `@phpstan-consistent-constructor`
and the conveniences never widen the constructor (they preset via `make()` + the
fluent withers only), so `new static(...)` is safe at level 9. This was the
explicitly-agreed plan (Greg, 2026-06-19); the alternative — factory classes
returning a configured plain `Where` — was rejected because it could not give the
OpenAPI projector a distinct intent-named type to surface.

## The numeric coercion is type fidelity in-memory, correctness at the data layer

PHP 8 already compares two *clean* numeric **strings** numerically (`'18' > '6'`
is `true`), so the classic pre-8 string-compare footgun does **not** reproduce for
clean values in the reference in-memory handler — the value the numeric
conveniences guarantee there is **type fidelity**: the coerced value reaches the
comparison (and, in the adapter slice, the SQL bind) as a real `int`/`float`,
never a string. The coercion's behavioural teeth land at the data layer
(index-using numeric comparisons, strict drivers) and in the genuinely temporal
case (`DateRange`, ADR 0076), where lexical and semantic ordering truly diverge
even in PHP 8. The scalar conveniences carry their value constraint so the
OpenAPI generator narrows the `filter[<key>]` schema from the one declaration.
