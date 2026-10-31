# The Server is an immutable, per-version configuration root and PSR-15 handler

A `Server` is an immutable value bundling one API version's configuration (registry, profiles, base URI, encoding options, factories, middleware); every `with…()`/`register()` returns a clone with its registries cloned too, so registration never leaks between instances. It is itself the PSR-15 request handler and the serializer resolver injected into resources. Several API versions are several `Server`s; choosing one per request is the caller's routing concern, kept out of core.
