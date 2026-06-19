# OpenAPI metadata contract and component projection

The OpenAPI generator splits along a **framework-agnostic metadata contract**
(`OpenApi\Metadata\*Interface`): core owns the JSON:API→OAS *semantics* but most of
the *data* (info, base URIs, server assignment, operation allow-lists, tag
refs/definitions, security schemes, custom actions) is app-/framework-side, so core
defines a small set of read interfaces describing "a server's worth of JSON:API
metadata" — `ServerMetadataInterface` (info/servers/jsonapi-version/tag-definitions/
security-schemes + the type list), `TypeMetadataInterface` (type, uriType, field
inventory, relations, operations, id policy, paginator kind, filters, sorts,
actions, tags, includable paths), `RelationMetadataInterface` and
`ActionMetadataInterface`, plus the `OperationType` / `PaginatorKind` /
`ActionScope` / `ActionInputMode` discriminator enums. The **full** contract is
defined now (including the operation/action/param accessors Slice 3 consumes) so it
is stable; the bundle implements it in Slice 4 from its compiled registry.

The `OpenApiProjector` consumes that contract and the Slice-1 `SchemaProjector` to
build the document **skeleton** + **component set** + **document envelopes** (§4.3) —
per-type resource/identifier/relationship-object schemas and create/update request
schemas, the shared error document and `jsonapi`/links/meta components, and the
single/collection envelopes. It emits **no `paths`** (Slice 3); a path-less OAS 3.1
document is valid because the projector always emits `components`.

**Named enum components (§4.8)** are hoisted via an `EnumComponentCollector` threaded
into `SchemaProjector`: when projecting **into a document**, a backed-enum schema
sourced from an `In` class-string is registered once (deduped on the class-string,
named by short class name, collision-suffixed) and replaced inline by a `$ref` to
`#/components/schemas/<Enum>`; **without** a collector the enum stays inline, so
standalone Slice-1 projection is unchanged. This needed a `$ref` capability on the
Slice-1 `Schema` VO (`Schema::ref()`), since a 2020-12 Schema Object expresses a
reference as `{"$ref": …}`.

A standalone serializer-only type (no field inventory) is tolerated: it gets a
permissive resource-object schema and no attribute/write-request components, mirroring
the bundle's existing "type with a serializer but no fields" case.
