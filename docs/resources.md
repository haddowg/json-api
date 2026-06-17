# Defining a resource

A Resource class describes one JSON:API type in a single declaration. You subclass
`Resource\AbstractResource`, set its `$type`, and implement `fields()`; that one
class satisfies **both** the serializer contract (turning a domain object into a
resource object on the way out) and the hydrator contract (filling a domain object
from a request body on the way in). For the 90% case you never write a serializer
or a hydrator by hand — you describe the type's fields once and the engine does the
rest.

This page is the on-ramp. Start here, declare a type, register it, and you have a
fully readable and writable resource. When the field DSL runs out, the page closes
by pointing you at the composed model — overriding just one concern, or skipping
the Resource class entirely.

> New here? See [Getting started](getting-started.md) and [Concepts](concepts.md)
> first; installation is covered in [index](index.md).

## The minimal Resource class

Subclass `AbstractResource`, declare the type, list the fields:

```php
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class AlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(200)->sortable(),
        ];
    }
}
```

Every entry in `fields()` is a [`FieldInterface`](fields.md) — an `Id`, an
attribute, or a relationship — and **declaration order is preserved in output**.
This is the whole contract for a basic type: `Id::make()` becomes the resource
object's `id`, and each attribute field becomes an `attributes` member read from
your domain object. The real [`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php)
adds dates, a decimal, a nested `Map`, and relationships, but the shape is the
same.

> **A note on names.** "Resource" is overloaded. The JSON:API spec's *resource
> object* — the `{type, id, attributes, relationships}` structure inside `data` —
> is emitted by the serialization engine as a plain array; there is no
> `ResourceObject` class you instantiate. The class you subclass here,
> `Resource\AbstractResource`, is the *Resource class*: a per-type serializer +
> hydrator. The lower-level [`SerializerInterface`](serializers.md) /
> [`HydratorInterface`](hydrators.md) contracts it satisfies are usable directly
> when you need full control. See [Concepts](concepts.md#the-three-meanings-of-resource).

## `$type` — the type member and the registry key

`$type` is doing two jobs at once. It is the JSON:API `type` member rendered in
every resource object of this kind, and it is the **key the resource registers
under** on a [`Server`](server.md). Relationship linkage and `?include` resolve a
related type by looking it up under this key, so the `$type` you declare here is
the same string a relationship field targets with `->type('albums')`.

## `$uriType` — the URL segment, decoupled from the type

By default a resource's URL path segment is its `$type`: an `albums` resource lives
under `/albums`. When you want the URL segment to differ from the JSON:API type —
a different pluralisation, a kebab-cased path — set the static `$uriType`:

```php
final class AlbumResource extends AbstractResource
{
    public static string $type = 'albums';
    public static string $uriType = 'album-catalogue'; // served at /album-catalogue
}
```

`uriType()` resolves to `$uriType` when set and falls back to `$type` otherwise:

```php
// src/Resource/AbstractResource.php
public function uriType(): string
{
    return static::$uriType !== '' ? static::$uriType : static::$type;
}
```

The segment is what hosts use when they build self links and `Location` headers —
the music-catalog handler reads it off the resource when echoing a created
resource's URL:

```php
// examples/music-catalog/src/Handler/MusicCatalogHandler.php
$uriType = $server->resourceFor($type)->uriType();
// …->withHeader('Location', $server->baseUri() . '/' . $uriType . '/' . $id);
```

Every example type uses the default (its `$uriType` equals its `$type`); set it
only when a type's wire name and its URL segment genuinely diverge.

## The overridable-method contract

`AbstractResource` exposes a small set of methods. Only `fields()` is required;
the rest carry sensible defaults you override per type.

| Method | Returns | Purpose |
|---|---|---|
| `fields()` | `list<FieldInterface>` | The attribute + relationship inventory (**required**). |
| `filters()` | `list<FilterInterface>` | The [filters](filters.md) this type accepts (default: none). |
| `sorts()` | `list<SortInterface>` | Computed / multi-column [sorts](sorts.md) beyond the field-derived ones (default: none). |
| `pagination()` | `?PaginatorInterface` | The default [pagination](pagination.md) for this type's collections (default: `null` → the server's). |
| `getDefaultIncludedRelationships(mixed $object)` | `list<string>` | Relationships included by default when the request carries no `?include` (default: none). |
| `allSorts()` | `list<SortInterface>` | **Derived for you** — every `->sortable()` field yields a `SortByField`, merged with `sorts()`. Rarely overridden. |

[`ArtistResource`](../examples/music-catalog/src/Resource/ArtistResource.php) shows
the common overrides — a `filters()` entry and a `sorts()` entry for a computed
column:

```php
// examples/music-catalog/src/Resource/ArtistResource.php
public function filters(): array
{
    return [Where::make('slug')->singular()];
}

public function sorts(): array
{
    // trackCount has no single sortable column, so it is a custom SortInterface.
    return [new TrackCountSort()];
}
```

`allSorts()` is the union the engine actually consults: it walks `fields()`,
derives a `SortByField` for each `->sortable()` field, then merges anything
`sorts()` adds (later keys win). Because every sortable attribute is already
covered, you only ever touch `sorts()` for a sort that doesn't map to one column.

### Narrowing hooks

Below those, a handful of hooks slice the field inventory for the engine — mostly
`protected` (`attributeFields()`, `relationFields()`, `idField()`), with the
relationship lookup `relationNamed()` exposed `public` for adapters. You rarely
override them, but they are the seams a data-layer adapter uses:

| Hook | Returns | Purpose |
|---|---|---|
| `attributeFields()` | `list<FieldInterface>` | The non-id, non-relation, non-hidden attribute fields the serialize/hydrate walks iterate. |
| `relationFields()` | `list<RelationInterface>` | The non-hidden relationship fields. |
| `relationNamed(string $name)` | `?RelationInterface` | (public) The relationship declared under member `$name`, or `null` — the single lookup the related / relationship endpoints and data-layer adapters call. |
| `idField()` | `?Id` | The declared `Id` field, or `null`. |

These are derived from `fields()` and cached; overriding them is an advanced escape
hatch, not part of the everyday contract.

## How fields drive serialization

When the engine serializes a domain object, it walks the non-hidden fields:

- The **`Id` field** produces the resource object's top-level `id`. With a plain
  `Id::make()` the id is read off the object's `id` property and rendered as a
  string.
- **Attribute fields** produce `attributes`, each read via a framework-agnostic
  accessor (an array / `ArrayAccess` key first, then on an object a `getXxx()` getter,
  an `isXxx()` getter, a member-named method, and finally a public property) — or via
  the field's own `serializeUsing()` / `extractUsing()` hook for a computed value.
  `displayTitle` on [`TrackResource`](../examples/music-catalog/src/Resource/TrackResource.php)
  is `computed()` and derived purely on read:

  ```php
  // examples/music-catalog/src/Resource/TrackResource.php
  Str::make('displayTitle')
      ->computed()
      ->readOnly()
      ->extractUsing(static fn(mixed $track): string => $track instanceof Track
          ? \sprintf('%d. %s', $track->trackNumber, $track->title)
          : ''),
  ```

- **Relationship fields** produce `relationships`, serializing the related type
  through the [server's registry](server.md).

Sparse fieldsets (`?fields[albums]=title`) and inclusion (`?include=artist`) are
applied by the engine, which reads the request and narrows the output — the
resource emits every eligible field. Mark a field `->hidden()` to drop it from
output entirely, or `->notSparseField()` to exempt it from sparse-fieldset
filtering. See [fields](fields.md) for the full builder surface.

## How fields drive hydration

For a `POST` (create) or `PATCH` (update), the same fields fill the domain object:

- **The id.** Two axes on the `Id` field decide where a created resource's id
  comes from. A client-supplied `id` is rejected by default with
  [`ClientGeneratedIdNotSupported`](errors-and-exceptions.md) (`403`) until you opt
  in with `allowClientId()` (optional) or `requireClientId()` (mandatory). When the
  client supplies none, the default is **store-provided** — core sets nothing and
  the persister/DB assigns the id; `generated()` (over a `uuid()`/`ulid()` format)
  or `generateUsing()` makes the *application* mint it instead:

  ```php
  Id::make();                       // store-provided (DB assigns the id)
  Id::make()->uuid()->generated();  // app mints a v4 UUID
  ```

  [`PlaylistResource`](../examples/music-catalog/src/Resource/PlaylistResource.php)
  opts in so a `POST` may carry its own UUID, paired with a UUID id format (the id
  lifecycle and formats are covered in [ids](ids.md)):

  ```php
  // examples/music-catalog/src/Resource/PlaylistResource.php
  Id::make()->uuid()->allowClientId(),
  ```

- **Attribute fields** write back through the accessor (or the field's
  `deserializeUsing()` / `fillUsing()` hook), **unless the field is read-only in
  that context** — `->readOnly()`, `->readOnlyOnCreate()`, `->readOnlyOnUpdate()`.
  A read-only field is silently skipped during hydration.
- **Relationship fields** are filled from the request's parsed linkage, not from a
  raw attribute value.

Hydration respects JSON:API update semantics: **an attribute absent from a `PATCH`
body is left unchanged** — the walk only touches members the request actually
carries.

## Registering a resource on a Server

A Resource class becomes active when you `register()` it on a
[`Server`](server.md), keyed by **class-string**. Registration is lazy: the class
is instantiated on first use through the server's resolver (or plain `new`):

```php
// examples/music-catalog/src/bootstrap.php
$server = Server::make()
    ->withBaseUri('https://music.example')
    ->withPsr17($psr17, $psr17)
    ->register(ArtistResource::class)
    ->register(AlbumResource::class)
    ->register(TrackResource::class, serializer: TrackSerializer::class)
    ->register(PlaylistResource::class, hydrator: PlaylistHydrator::class)
    // …
```

Registering two resources for the same `$type` is a wiring error — a
`\LogicException` (`A resource is already registered for type "albums".`). **The
registry is also the relationship resolver**, so every type that participates in a
relationship or an `?include` must be registered; an unregistered related type
can't be linked or included.

## Relationships are fields too

A relationship is just another `fields()` entry. Declare the related type with
`->type()`; the related resource serializes through the registry:

```php
// examples/music-catalog/src/Resource/AlbumResource.php
BelongsTo::make('artist')->type('artists'),
HasMany::make('tracks')
    ->type('tracks')
    ->paginate(PagePaginator::make()->withDefaultPerPage(2))
    ->dataOnlyWhenLoaded(),
```

That is the whole teaser — `BelongsTo`/`HasOne`/`HasMany`/`BelongsToMany`/
`MorphTo` and their options (linkage policy, pivot fields, paginated related
collections, replacement guards) are covered in [relations](relations.md) and the
relationship field reference in [fields](fields.md#relationships-are-fields-too).

## Field constraints are metadata

The constraint methods you chain onto a field — `->required()`, `->maxLength(200)`,
`->min(1)` — are **declarative metadata**. The core never executes them against
incoming data; it only records them. They are consumed by the optional
[JSON Schema compiler](schema-validation.md) for structural request validation, and
they are the vocabulary framework adapters translate into real validation. See
[constraints](constraints.md) for the full vocabulary and the create/update context
model.

## When you need more control

The field DSL is the common path, not a ceiling. The type model is **composed** —
serializer, hydrator, relations, provider and persister are independent — and
`AbstractResource` is simply the sugar that bundles serializer + hydrator +
relations for you. When you need more, peel off exactly one layer:

- **Override just the serializer** when reads need request-aware or computed output
  the field walk can't express. The music app registers `TrackResource` with a
  custom serializer (it wins for reads; the resource still hydrates writes):

  ```php
  ->register(TrackResource::class, serializer: TrackSerializer::class)
  ```

- **Override just the hydrator** when a write splits a member across columns,
  derives related data, or runs a multi-step write. `PlaylistResource` registers a
  custom hydrator (it wins for writes; the resource still serializes reads):

  ```php
  ->register(PlaylistResource::class, hydrator: PlaylistHydrator::class)
  ```

  The registry resolves an override ahead of the Resource class and falls back to
  the resource for the concern you didn't override.

- **Skip the Resource class entirely** for a bare serializer + hydrator pair (or
  just one) registered under an explicit `$type` with `registerSerializerHydrator()`
  — the read-only `charts` type does exactly this. See
  [capability composition](capability-composition.md) for the full composed model.

## Next / see also

- [Fields](fields.md) — every field type and the fluent builder surface.
- [Relations](relations.md) — relationship fields, linkage, and endpoints.
- [Filters](filters.md) / [Sorts](sorts.md) / [Pagination](pagination.md) — query-shaping.
- [Constraints](constraints.md) — the constraint vocabulary and create/update contexts.
- [Ids](ids.md) — the id field, formats, and client-generated ids.
- [Serializers](serializers.md) / [Hydrators](hydrators.md) — the per-concern overrides.
- [Capability composition](capability-composition.md) — the composed type model behind `AbstractResource`.
- [Server](server.md) — registration and the registry.
