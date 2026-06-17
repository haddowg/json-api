# The Relationship Queries profile URI is its published specification URL

The `RelationshipQueriesProfile` canonical URI moves from the placeholder
`https://haddowg.dev/profiles/relationship-queries` (an unregistered domain that
never resolved) to `https://haddowg.github.io/json-api/profiles/relationship-queries/`,
which is a real page on the existing GitHub Pages docs site carrying the profile's
specification — so the URI a client negotiates now **dereferences to a
human-readable description of what it means**, exactly as the bundled
cursor-pagination profile's URI dereferences to Ethan Resnick's published spec.
We chose the `github.io` docs URL over keeping a `haddowg.dev` vanity domain because
it resolves the moment the docs deploy with no DNS or custom-domain setup; a vanity
domain can be adopted later behind a CNAME without changing the spec's content. The
URI is a stable wire identifier (clients match it in the `Accept` `profile`
parameter), so it is fixed here before v1 rather than left as a dead link.
