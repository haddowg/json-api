# Three composable layers with public escape hatches

The public API is three layers supported at once: a fluent `Resource` that declares a type's fields once and drives both directions, the `Serializer`/`Hydrator` contracts it implements, and the engine beneath. A consumer can register only a `Resource`, override one side with a hand-written serializer or hydrator, or register a bare pair with no `Resource`; the registry resolves an override ahead of the `Resource`. We accept a larger stable surface so there is no cliff between the common case and the escape hatch.

## Consequences

Optional serializer capabilities (include controls, self-link opt-out, declared field names, counting) are separate interfaces read with `instanceof`, never methods added to `SerializerInterface`, so bare serializers keep working.
