# `hasResourceFor()` completes the registry presence-check trio

`Server` and `ResourceRegistry` gained `hasResourceFor(string $type): bool` — true when
a type was registered with a Resource class, false for a bare serializer/hydrator pair or
an unregistered type. It is the presence-check mirror of `resourceFor()`, completing the
symmetry already established by `serializerFor()`/`hasSerializerFor()` and
`hydratorFor()`/`hasHydratorFor()`.

It fills a real gap: before it, the only way to ask "does this type have a Resource class,
or is it a standalone bare pair?" was to call `resourceFor()` and catch
`NoResourceRegistered` — exception-for-control-flow. A custom operation handler that
branches on whether a type carries field-driven metadata (vs a hand-written serializer)
now has a clean predicate. Purely additive, landed before the 1.0 freeze.
