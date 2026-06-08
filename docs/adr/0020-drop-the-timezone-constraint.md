# Drop the `Timezone` constraint from the vocabulary

The `Timezone` constraint asserted that a date-time value's timezone is one of an
allowed set of IANA identifiers (`Europe/London`). In practice it cannot be
resolved well: an ISO-8601 value on the wire carries a numeric **offset**
(`+01:00`), not a named zone, and an offset cannot be reversed to an IANA name —
so the constraint only ever matched when the input happened to carry a named zone,
which clients do not send. A well-designed API normalizes any incoming instant to
its own canonical zone internally and formats back for display; it does not police
the client's wire timezone. The constraint therefore promised a check it could not
honestly perform, so it is removed: the `Timezone` value object and the
`DateTime::timezone()` builder are gone, while `DateTime::useTimezone()` — which
converts a *hydrated* value into a storage zone, an unrelated and useful feature —
stays.

`Before`/`After`/`Between` remain the date vocabulary; they compare instants and
are well-defined regardless of the wire offset.
