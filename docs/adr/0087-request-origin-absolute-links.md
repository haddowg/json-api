# Links resolve to the request origin when no base URI is configured

Every generated link (resource `self`, document `self`, related, relationship
`self`/`related`, pagination `first`/`prev`/`next`/`last`, error `about`/`type`)
is prefixed with a base URI. Previously an **empty** `base_uri` — the default —
produced **host-relative** links (`/albums/1`); a host running one API behind a
known origin had to configure `base_uri` to its own host just to emit absolute
URLs, duplicating information already on the request. We now resolve the base from
the **request origin** when no fixed base is configured: `<scheme>://<authority>`
of the PSR-7 request URI (authority = host, with port and userinfo when present,
already proxy/forwarded-host-aware upstream). So an empty `base_uri` yields
request-absolute links (`https://music.example/albums/1` when the request `Host`
is `music.example`), and a multi-host deployment serves each host correct absolute
links with **no** configuration. A non-empty `base_uri` is unchanged in intent —
it pins a fixed canonical host regardless of the request — but is now **trailing-
slash tolerant** (`rtrim`'d of `/` before prepending), so `https://host/` and
`/api/` never double-slash. A request with no resolvable origin (a relative /
path-only request URI: no authority, or an authority with no scheme) degrades to
the empty base, leaving links host-relative rather than emitting a broken `://path`
prefix.

The resolution is a single pure function — `Server\RequestBaseUri::resolve(string
$configuredBaseUri, UriInterface $requestUri): string` — applied **once per
response**, where the request is in scope, and the already-resolved absolute base
is threaded into `ResourceTransformation->baseUri` and the `Schema\Link\*Links`
value objects exactly as the raw configured base was before; the downstream
`Link`/`Links` transform code is unchanged. Error links are author/exception
supplied rather than built downstream from a threaded base, so `ErrorResponse`
resolves the base and **rebinds** it onto the document-level and per-error links —
a no-op for a container that already pinned its own base via `withBaseUri(...)`
(a deliberate canonical base wins) and for an absolute href.

`Link::transform()`/`LinkObject::transform()` now **skip the base prefix for an
already-absolute href** (a scheme-qualified or protocol-relative URL). This keeps
the historic "absolute href + empty base" and "relative href + base" cases
identical, makes the previously-corrupting "absolute href + non-empty base" case a
correct no-op, and means an author-supplied absolute error documentation URL is
never prefixed by the request origin.

## Consequences

- The change is observable only for an **empty `base_uri` served over a
  host-bearing request** — exactly the case the existing core tests never
  exercised (they pin a configured base or use a hostless request URI), so no
  existing assertion flipped; the new behaviour is proven by dedicated tests.
- A host that previously relied on **host-relative** output with no `base_uri`
  while serving over a real host now emits absolute URLs. To keep host-relative
  output, leave `base_uri` empty **and** ensure the request URI carries no
  authority (e.g. an internal/relative request), or configure a path-only
  `base_uri`.
