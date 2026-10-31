# The metadata contract grows through opt-in interfaces, never new methods

Adapters implement the `OpenApi\Metadata` interfaces, so adding a method to `TypeMetadataInterface`, `RelationMetadataInterface`, `ActionMetadataInterface` or `ServerMetadataInterface` breaks them. Every addition is therefore a separate `Declares…`/`Contributes…` interface extending the base (operation extensions, write-only relations, singular names, examples, scopes, …) that the projector checks with `instanceof`, so existing integrations compile and project unchanged until they opt in.
