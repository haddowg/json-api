# A temporal `format` keyword is emitted only when the field really writes RFC 3339

`DateTime` (and its `Date` / `Time` subclasses) carries a configurable serialization
format, but the generated schemas derived `format: date-time` / `date` / `time` from
the field's PHP **class** and ignored that format entirely. All three keywords are
defined as RFC 3339 productions, so a server configured with anything else was
advertising a shape it does not write. A generated client coerces on the keyword and
gets a parse failure, or a plausible-looking wrong value, from a response the document
called valid.

`DateTime::schemaFormat()` now answers the question from the format string instead: it
renders a fixed set of reference instants and emits the keyword only when every one of
them comes out RFC 3339 **and** parses back to the instant it came from. A field that
fails is documented as the plain `string` it is, with its shape given by example in the
`description` — the same lossy-degradation note the projector already uses for a
constraint with no faithful keyword. The check lives on the field so the OpenAPI
projector and the body-validation `SchemaCompiler` share one answer; the compiler was
lying in the more damaging direction, rejecting the very bodies its own hydrator
accepts.

## Consequences

`Time`'s default format `H:i:s` is one of the failures. RFC 3339 `full-time` requires a
time-offset and a wall-clock time has none, so `format: time` was never true for the
default and a client coercing on it produced a `DateTimeImmutable` carrying today's
date. The wire output is unchanged — only the documentation stops overclaiming — and
`->format('H:i:sP')` opts a field back into the keyword. JSON Schema 2020-12 has no
offsetless time production, so a plain string genuinely is the most a standard document
can say here.

## Considered options

Adding a `pattern` alongside the keyword was rejected in both its forms. Keeping
`format: date-time` and pinning the real shape with a `pattern` leaves the false keyword
in place for the many generators that key on it alone, and a strict validator then sees
a schema whose two keywords contradict each other. Deriving the pattern at all is the
deeper problem: a PHP format string can render variable-width fields (`j`, `n`, `G`) and
open-ended timezone names (`e`, `T`) that change with the tzdata release, so a regex
inferred from one is a universal claim built from samples. Too loose and it says
nothing; too tight and it rejects a response the server is entitled to send, which is
worse for a consumer than no regex at all. The reference-instant check makes no such
universal claim — it only ever withholds a keyword — so sampling is sound there and not
for a pattern.

An `x-` extension carrying the PHP format string was rejected for pushing
language-specific knowledge into a language-agnostic document, and for leaving the false
`format` in place for everyone who does not read it. Rejecting a non-RFC-3339 format as
a configuration error was rejected as breaking for servers with a wire format they do
not control, and it would have made `Time`'s own default an error.
