# Range / DateRange structured-value filter

`Range` (and its date specialisation `DateRange`) is a **genuinely new filter
type**, not a `Where` preset: it matches an inclusive `min <= value <= max` from a
**structured value** carrying an optional lower and/or upper bound, so one filter
key expresses both predicates and an open-ended range works (either bound may be
omitted; an entirely absent value is a no-op). The wire shape is **nested** —
`?filter[<key>][min]=…&filter[<key>][max]=…` — which a framework already parses
into `['min' => …, 'max' => …]`; the handler receives that array verbatim as the
filter value. This was chosen over a delimited `10,100` because it is
self-documenting, handles open bounds cleanly, and maps to an OpenAPI `deepObject`
parameter (the deepObject *parameter* rendering is a follow-up slice; this slice
ships the object value schema and the apply).

It implements `FilterInterface` directly (using `HasValueConstraints`, like
`WhereThrough`) rather than extending `Where`, because its value is structured and
its apply runs two predicates — so the reference `ArrayFilterHandler` gains a
dedicated `instanceof Range` arm. That arm coerces **both** the column value and
each present bound through the filter's deserializer before comparing, so the
range is numeric/temporal rather than lexical.

`DateRange` extends `Range`, presetting an ISO-8601 → `\DateTimeImmutable`
deserializer. This is a real correctness fix even in PHP 8: two ISO-8601 instants
written with different UTC offsets are the same moment but compare **unequal
lexically**, so a bare string range wrongly excludes the boundary; coercing to
`\DateTimeImmutable` compares the instants. An unparseable or blank bound is
returned unchanged (so a constraint-rejected value reaches the validator as-sent
rather than throwing in the filter). Database adapters translate a `Range` into
two push-down `andWhere` predicates (the adapter slice).
