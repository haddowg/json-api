# Server-composed filter groups; client-driven boolean algebra declined

Filters combined only by implicit AND across distinct `filter[<key>]` keys — there
was no OR across columns, no named boolean grouping, and a single-column canned
toggle was reachable only through a `->deserializeUsing(fn() => $literal)` trick
with no declarative form. We add composite `WhereAll`/`WhereAny` filter value
objects (a `key()` plus `list<FilterInterface> $children`) that the resource author
composes **server-side**: the group combines its children with AND/OR under one
`filter[<key>]`, passes its own request value **uniformly to every child** (so a
fanned group is multi-column search — `filter[q]=foo` → `name LIKE foo OR email LIKE
foo` — while a fixed-child group is a canned toggle), ignores each child's own
`key()` as a request parameter while still using it as the child's column, and may
nest arbitrarily. We also add `->fixed($value)` to the `Where` family (**no new
filter type**): it pins the compared value so the request value is ignored and the
key becomes a presence trigger — distinct from `->default()`, which a client can
override — recorded as a real property so the OpenAPI projector documents it
honestly, but executed through the existing `deserialize` seam so **no handler
changes** to run it.

We **decline** the client-driven `filter[and]`/`[or]`/`[not]` model (json-api-server's
`SupportsBooleanFilters`): it does not project to a single `filter[<key>]` OpenAPI
parameter and it hands boolean-algebra composition to the caller, reversing the
owner-vetted allow-list stance the comparison docs advertise. Server-composition
keeps query complexity author-controlled and every group projecting as one scalar
(or presence) parameter. Chosen over a new `Fixed`/`Constant` filter type (the
`Where`-family wither reuses the existing dispatch and `deserialize` seam), over the
client-driven boolean model, and over leaving grouping to bespoke per-provider
custom arms (which forfeits the declarative, portable, OpenAPI-projected form even
though a single-provider consumer's custom arm is cheap).
