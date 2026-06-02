# Three composable layers with public escape hatches

The public API is three layers, all supported simultaneously:

1. a fluent **Resource** — declare a type's fields once, driving both
   serialization and hydration;
2. the lower-level **Serializer** and **Hydrator** contracts it implements; and
3. the orchestration and serialization engine beneath them.

A consumer can register only a `Resource`, override just one side with a
hand-written `Serializer` or `Hydrator`, or register a bare serializer+hydrator
pair with no `Resource` at all — the registry resolves an override ahead of the
`Resource` fallback. The cost is a larger public surface to keep stable across
versions; the benefit is no cliff between the 95% case and the escape hatch when
field-walking isn't enough.
