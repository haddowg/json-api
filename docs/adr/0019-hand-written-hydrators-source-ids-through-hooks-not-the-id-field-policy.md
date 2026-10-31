# The hand-written hydrator family sources ids through hooks, not the `Id`-field policy

The `Id`-field id policy (client-id acceptance, generated or store-provided fallback) governs only `AbstractResource`. `AbstractHydrator`/`AbstractCreateHydrator` deliberately keep their abstract `generateId()`/`setId()`/`validateClientGeneratedId()` hooks instead: they are the field-inventory-free escape hatch with no `Id` field to read, and an abstract `generateId()` can never silently mint a UUID. A test pins that both paths express the same choices so they cannot drift unnoticed.
