# An ordered comparison against `null` never matches

The in-memory `ArrayFilterHandler` is the conformance witness every data-layer adapter must match, so it follows SQL's three-valued logic rather than PHP's coercion: `>`, `>=`, `<`, `<=` (and so a range bound) exclude a row whose value is `null`. Equality keeps PHP semantics, with `WhereNull`/`WhereNotNull` for null tests, and null ordering in sorts is left to each engine.
