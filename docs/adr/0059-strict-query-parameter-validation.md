# Reject an unrecognized query-parameter family by default

The baseline `validateQueryParams()` only rejects an **all-`a-z`** unrecognized
name; a **well-named** custom parameter (one carrying a non-`a-z` character, e.g.
`?withCont=...`, a misspelled `relatedQ[...]`, a typo'd `myFilter`) the server
does not recognize was silently dropped, so a client typo returned a
wrong-but-`200` result. The spec mandates a `400` for a param that breaks the
custom-parameter naming rule *and* is unrecognized, and **permits** a `400` for a
well-named one the server does not support — so we now take the reject option **by
default**: `Server::dispatch()` runs an up-front `StrictQueryParameterValidator`
that rejects any query parameter whose **family base name** is not in the
recognized set with the existing `QueryParamUnrecognized` (`400`,
`source.parameter` = the offending base).

The recognized set is assembled per request, per the resolved primary resource's
vocabulary: the six reserved JSON:API families (whose *internal* key validation —
an unknown `filter`/`sort` key, a malformed `page` — is unchanged and remains each
family's own job; this slice only adds the **family**-level check), the always-on
`withCount`, the host-registered custom families
(`Server::withCustomQueryParameter(...)`), and the reserved keywords of every
registered profile the request **negotiated** — so the relationship-queries
profile's `relatedQuery` / `rQ` families are recognized only when that profile's
URI is in the `Accept` `profile` parameter (mirroring the gate the profile's own
parsers use) and rejected otherwise.

It runs in `dispatch()` rather than the PSR-15 negotiation middleware so a
framework integration driving the lifecycle through `Server::dispatch()` (the
bundle's kernel listeners) gets the resource- and registry-aware check uniformly,
before any `serving`/authorization hook — a wrong param is rejected before work
begins. The behaviour is a deliberate default-on **behaviour change**;
`Server::withStrictQueryParameters(false)` restores the tolerant-by-default
silent-ignore behaviour for hosts that need it.
