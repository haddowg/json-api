# UnsupportedFilter / UnsupportedSort carry a generic remediation hint

A custom filter or sort with no registered handler raises `UnsupportedFilter` /
`UnsupportedSort` (a 500 server-configuration error). The most useful remediation is
data-layer-specific — e.g. "register a Doctrine filter arm" — but core knows nothing of
any concrete data layer, and must not: Doctrine is one reference provider, not the only
possible one.

So the two exceptions gain an **optional, data-layer-agnostic `$hint`** the raising
handler may supply, appended to the message (and surfaced via a public `hint` property).
Core never fills it; the provider that actually fails to handle the filter/sort — which
alone knows its own extension seam — passes the guidance. This keeps core generic while
letting a provider point a developer at the exact fix, rather than baking a provider
concern into a core exception.
