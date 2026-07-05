# An ordered comparison against `null` never matches

- **Status:** accepted

The reference in-memory `ArrayFilterHandler` is the conformance witness that a
data-layer adapter (Doctrine, Eloquent, …) must match byte-for-byte, so its
comparison semantics must be the ones a SQL backend gives — not PHP's. An ordered
comparison (`>`, `>=`, `<`, `<=`, and therefore a `Range`/`DateRange` bound) against
a column whose value is `null` now **excludes** the row: either operand being `null`
yields false, mirroring SQL three-valued logic (the predicate is UNKNOWN) rather than
PHP's silent coercion of `null` toward `0`/`''` (which had `null <= 9.0` match). The
`Range` path reads the raw column *before* its deserializer so a mapping that coerces
`null` (e.g. `null -> 0`) cannot smuggle a NULL row into a present bound.

Scope is **ordered** comparison only. Loose/strict equality (`=`/`==`/`!=`/`<>`/`===`)
keeps native PHP semantics — a `null` test is expressed with the dedicated `WhereNull`
/`WhereNotNull` filters — and null *ordering* in sorts is a separate, engine-defined
concern left untouched. This resolves the witness divergence recorded downstream in
`haddowg/json-api-laravel` ADR 0003, whose reference Eloquent provider already had SQL
semantics; the fix makes the in-memory witness converge with it, so a null-bearing
comparison or range returns the same set on every provider.
