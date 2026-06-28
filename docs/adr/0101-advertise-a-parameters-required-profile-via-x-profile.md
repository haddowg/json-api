# Advertise a parameter's required JSON:API profile via `x-profile`

- **Status:** Accepted
- **Date:** 2026-06-28

A query parameter recognised **only when a JSON:API profile is negotiated** (today
`?withCount`, gated by the Countable profile — the runtime rejects it as an unknown
parameter under strict query-parameter validation unless the profile is in the request's
`Accept`) had no machine-readable signal of that requirement in the projected OpenAPI;
the obligation lived only in the parameter's prose `description`. A generated client
therefore could not know it must add `Accept: …; profile="<uri>"` to use the parameter,
and would get a `400` instead.

The projector now emits a vendor extension `x-profile` (the profile's canonical URI) on
each such parameter. `Parameter` gains a generic `withExtension()` (mirroring `Schema`),
and `withCountParameter()` attaches `x-profile: <CountableProfile::URI>`. The extension is
emitted after the standard keywords in both the array and JSON forms. Profiles are
otherwise *advisory* (an unrecognised profile is never rejected), so this is purely a
self-describing hint — it changes no runtime behaviour and no other projected output.

## Consequences

- A spec consumer (e.g. a codegen) can discover, structurally and per-parameter, which
  profile a parameter requires and negotiate it — including a custom app-defined profile,
  since the projector emits whatever profile URI the parameter belongs to (no hardcoded
  catalogue). The cursor-pagination profile needs no annotation: `page[cursor]` is a
  reserved family recognised without a profile, and the cursor profile is response-echoed.
- The atomic *extension* needs no `x-` either — it is already structural on the
  `/operations` content-type media-type parameter (`ext="…/ext/atomic"`).
- A future profile whose parameters the projector does not yet emit (the Relationship
  Queries profile's `relatedQuery`/`rQ` family is not currently projected) will gain
  `x-profile` automatically once those parameters are projected.
