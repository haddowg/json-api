# Upgrading within 0.x

`haddowg/json-api` is pre-1.0. While the version is `0.x`, the public API is not
yet frozen and **breaking changes may land between minor versions** (a `0.x`
bump). This is deliberate: the surface is still being refined toward a stable
`1.0`, and holding back an improvement to preserve a not-yet-stable API would
trade long-term quality for short-term convenience. If you need an unchanging
surface, pin to a release and wait for `1.0.0`.

Every breaking change is recorded in two places: the project changelog
(generated from Conventional Commits by [release-please](https://github.com/googleapis/release-please)
once the first release is cut) and this page, which collects the consumer-facing
renames and moves with the search-and-replace you need to follow them. Changes here are almost always mechanical — a
type moved namespace, a method was renamed — and bounded precisely because the
package is still `0.x`.

## How to read an entry

Each entry names the version it landed in, what changed, and the migration. Apply
them in order if you are skipping several versions. When a change is more than a
rename (a behavioural or signature change), the entry spells out what to check
beyond the find-and-replace.

## Pre-1.0 changes

These changes predate the first tagged release; they are listed so the rationale
is on record and to seed the format for future entries. Consumers starting on the
first published `0.x` release are already on the post-change API and need take no
action.

### Resource registry renamed (`SchemaRegistry` → `ResourceRegistry`)

The registry that holds the registered Resource classes was called "schema",
which clashed with the class actually being named `Resource\AbstractResource`. The
registry and its accessors were renamed so the vocabulary is consistent — the
fluent class is a *Resource class*, never a "schema". (`Validation\SchemaCompiler`
and `Validation\SchemaProvider` keep their names: those are *JSON Schema*, a
different thing.)

- `Server\SchemaRegistry` → `Server\ResourceRegistry`
- `Server::schemas()` → `Server::resources()`
- `ResourceRegistry::schemaFor()` → `ResourceRegistry::resourceFor()`
- Migration: search-and-replace the three names above. The common path
  (`->register(...)`, `->serializerFor()`, `->hydratorFor()`) is unchanged.

### Per-type serializer moved to `Serializer\*`

The per-resource-type serializer contract — the former `Schema\Resource\ResourceInterface`
and its `AbstractResource` base — was renamed to top-level **`Serializer\SerializerInterface`**
and **`Serializer\AbstractSerializer`**. This frees the `Resource\*` namespace for
the [Resource class](resources.md) layer (`Resource\AbstractResource`, `Resource\Field\*`,
and the constraint/filter/sort vocabularies), which is now the recommended way to
declare a resource type.

- Migration: implement / extend `haddowg\JsonApi\Serializer\SerializerInterface` /
  `AbstractSerializer` where you previously used the `Schema\Resource\*` types. See
  [Serializers](serializers.md) for full control of serialization.
- Note the naming split this introduces: the `Resource\AbstractResource` *Resource
  class* satisfies the `Serializer\*` contract. See
  [Concepts](concepts.md) for the vocabulary.

### Pagination rewritten as `Paginator` + `Page`

The former `PaginationLinkProviderInterface` and its collection-side trait pattern were
**replaced** by an explicit strategy/value-object split under
`Pagination\*`: a `Paginator` strategy reads the `page[…]` query params and
produces a `Page` value object that owns link (`first`/`prev`/`next`/`last`) and
`meta.page` emission. Collections no longer carry any pagination concern.

- Migration: stop implementing the link-provider interface or mixing the trait into
  a collection. Choose a paginator strategy, produce a `Page`, and render it with
  `DataResponse::fromPage($page, $serializer)`. See [Pagination](pagination.md).

## Related pages

- [Spec compliance](spec-compliance.md) — the canonical JSON:API 1.1 coverage reference.
- [Documentation index](README.md) — the full page list.
