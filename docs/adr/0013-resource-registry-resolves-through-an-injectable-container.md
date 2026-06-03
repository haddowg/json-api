# The resource registry resolves instances through an injectable container/resolver

`ResourceRegistry` used to call `new $resource()` eagerly at registration just to
read the static `$type` — forbidding constructor dependencies and instantiating
every resource at boot, wrong for a container-managed integration (e.g. a Symfony
bundle) where Resources, serializers, and hydrators are autowired services. It now
reads the type from the static `::$type` *without* constructing, and builds
instances lazily on first lookup through an optional injected resolver (a
`callable(class-string): object` or a PSR-11 container, normalised to a `\Closure`),
falling back to plain `new` when none is injected. The immutable `Server` threads
the resolver through its clones via `withContainer()`, and a bare serializer+hydrator
pair (no Resource class, no `::$type`) is keyed by explicit type via
`registerSerializerHydrator()`.

This refines [ADR 0002](0002-framework-agnostic-on-psr-standards.md) (the library
accepts a container, never assumes one) and
[ADR 0010](0010-server-is-immutable-per-version-root-and-psr15-handler.md). One
behavioural shift: a Resource constructor that throws now fails on first lookup,
not at `register()`.
