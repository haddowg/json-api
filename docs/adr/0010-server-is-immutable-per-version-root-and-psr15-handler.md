# The Server is an immutable, per-version configuration root and PSR-15 handler

A `Server` is an immutable value — every `with…()` and `register()` returns a
clone, and nested registries are cloned too so registration never leaks between
instances. It bundles one API version's configuration (resource registry,
profiles, base URI, encoding options, PSR-17 factories, middleware) and plays two
roles directly: it is a PSR-15 `RequestHandlerInterface` that folds its middleware
over the inner handler, and it is the serializer resolver injected into resources.

Multiple API versions are simply multiple `Server` instances; selecting one for a
given request is the caller's routing concern, kept deliberately outside core.
