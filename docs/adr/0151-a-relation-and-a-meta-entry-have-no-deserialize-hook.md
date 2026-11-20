# A relation and a meta entry have no deserialize hook

A relation writes its linkage through `fillUsing()` or its column, and an `additionalProperties` meta entry hands its members to `fillUsing()` cast by `of()`, so a `deserializeUsing()` on either was accepted and never called, the same dead hook ADR 0147 removed from objects and the id. Their builders no longer use `DeclaresDeserializeHook`, so declaring one is an undefined-method error PHPStan reports, and both keep `fillUsing()`.
