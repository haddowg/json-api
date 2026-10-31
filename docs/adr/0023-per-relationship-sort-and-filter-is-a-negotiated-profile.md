# Per-relationship sort and filter is a negotiated profile

A client sorts and filters a relationship's linkage from the primary request through the Relationship Queries profile (`relatedQuery[<include-path>][sort|filter]`, shorthand `rQ`), parsed only when the profile is negotiated in `Accept`, so the families cannot collide with a relationship named after them. Core parses the families and renders the relationship's own pagination links in plain form; the windowed page comes from the host through an injected resolver, since core never queries. The profile URI is its published specification page (`https://haddowg.github.io/json-api/profiles/relationship-queries/`), a stable wire identifier that dereferences to what it means.

## Consequences

`page` is not part of the profile: an addressed relationship renders page 1 and is navigated by its own links. On a conflict the canonical `relatedQuery` wins over `rQ`.
