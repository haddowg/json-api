# Relation factories require the related type as a native argument

A relation is meaningless without its related type, so `make()` takes it as a required argument: a `string` for a monomorphic relation, a non-empty list for a polymorphic one. PHP's LSP rules forbid adding a required parameter or narrowing a union on an inherited static method, so the single-argument `make()` lives on attributes only, and each relation family declares its own typed `make()`; omitting the type or passing the wrong shape is then a static-analysis error rather than a runtime surprise.
