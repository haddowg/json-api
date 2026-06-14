# A server-level `serving` hook fires before every operation

`Server::withServing(\Closure)` registers a request-scoped handler that
`Server::dispatch()` fires once — before the operation handler runs — passing the
JSON:API request resolved from the operation context; a handler may throw a
`JsonApiExceptionInterface` to abort, and the throw propagates out of `dispatch()`
unchanged so the operation never runs. We add this because cross-cutting,
request-wide concerns (authorization gates, request setup, the imperative-validation
escape hatch) have no seam today other than decorating the single operation handler,
which is coarse and forces every concern through one wrapper; a registered, appendable
list of small handlers fired at the dispatch boundary is the natural place for them and
gives **core-direct** consumers the same hook the framework bundle builds its
per-operation lifecycle on. The wither is immutable and **appends** (mirroring
`withDefaultPaginator()`/`withBaseUri()`/`withMaxIncludeDepth()`: clone + push), so
handlers fire in registration order; firing is skipped for a programmatic dispatch
with no HTTP message (there is no request to gate). The hook is deliberately
`serving`-only — a server-level seam fired in `dispatch()` — because core has no CRUD
lifecycle of its own to fire per-operation hooks in (persistence and the operation flow
live in the integration layer), so the finer-grained before/after hooks belong there,
built over this seam.
