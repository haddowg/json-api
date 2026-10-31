# Immutable value objects, with deliberate carve-outs

Every data type defaults to a `final readonly` value object (promoted public properties, no getters, named constructors, no setters), with two intentional exceptions a reader should not "fix". The request and response layers are not `readonly`: their `with…()` methods clone-then-assign, which readonly forbids, as do their lazy per-request parse caches. Fluent field and filter declarations are mutable builders so a declaration reads as one chained expression, but `make()` returns a builder that `build()` freezes into a separate `final readonly` value object, so the mutability is confined to authoring and everything the engine consumes is immutable.

## Consequences

Consumers read built fields through `AbstractResource::allFields()`, never the raw `fields()` list; `when()`/`onCreate()`/`onUpdate()` closures receive the builder. Sugar types with no behaviour of their own (`Email`, `Url`, …) are builder facades that build a plain `Str`.
