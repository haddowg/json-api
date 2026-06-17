# `?withCount` is gated behind a Relationship Counts profile

The `withCount` query parameter — the flat, comma-separated relationship-count
request that adds a `total` member to a named relationship object's `meta` — is now
a JSON:API **profile**, `Schema\Profile\RelationshipCountsProfile`
(`https://haddowg.github.io/json-api/profiles/relationship-counts/`), rather than an
always-on implementation-specific parameter. `parseCountedRelationships()` returns
an empty set unless the client negotiated the profile's URI in the `Accept`
`profile` parameter, and the strict query-parameter recognized set no longer
hard-codes `withCount` — it is recognized only as the negotiated profile's keyword,
exactly like the Relationship Queries profile's `relatedQuery`/`rQ`. This makes the
behaviour opt-in and self-describing (the negotiated URI dereferences to its
specification), consistent with the other bundled profiles, and frees the
`withCount` name from any collision when the profile is not in play. The change is
breaking — a bare `?withCount` without negotiating the profile is now an
unrecognized parameter under strict validation — and is taken pre-1.0 when the cost
is lowest.
