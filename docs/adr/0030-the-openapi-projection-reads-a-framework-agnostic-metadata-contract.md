# The OpenAPI projection reads a framework-agnostic metadata contract

Core owns the JSON:API-to-OpenAPI semantics, but most of the data (info, servers, server assignment, operation allow-lists, security, actions, tags) lives in the framework, so the projector consumes read-only `OpenApi\Metadata` interfaces (`ServerMetadataInterface`, `TypeMetadataInterface`, `RelationMetadataInterface`, `ActionMetadataInterface`) that each integration implements from its own registry. The projector is pure: metadata in, an immutable document out, no I/O.
