# Relationships and the relation DSL

Relations let a resource link to other resources. You declare them alongside
your attributes in `fields()`, and the library renders the JSON:API
`relationships` member — linkage (resource identifiers), conventional `self` and
`related` links, and (on request) the full related resources in `included`. This
page covers every relation type and the full policy surface that shapes how each
one renders, links, paginates, and mutates.

A relation is a field, so it shares the [field](fields.md) builder surface
(`storedAs()`, `computed()`, `extractUsing()`, the read/write hooks) and adds a
relationship-specific surface on top. The related resource always serializes
through the [serializer](serializers.md) registered for its type, so you declare
the *allowed* related type(s) with `type()` / `types()` and the registry does the
rest. Declaring the related type also auto-adds a `RelationshipType` inbound
constraint, so a write that points the relation at the wrong type is rejected.
Read the declared set back with the `relatedTypes()` accessor; for a polymorphic
relation the concrete member serializer is chosen per object at render time (the
`resolveSerializer` hook covered in [serializers](serializers.md#polymorphic-serialization-the-read-side-of-polymorphism)).

If you are new to the data model, [concepts](concepts.md) introduces
relationships as a resource member; this page is the working reference.

## The relation types

There are six relation field classes. The first four are monomorphic (one
allowed related type); the last two are polymorphic (several).

| Class | Cardinality | Backing | Declared with |
| --- | --- | --- | --- |
| `BelongsTo` | to-one | FK on the owning model | `->type('artists')` |
| `HasOne` | to-one | FK on the related model | `->type('albums')` |
| `HasMany` | to-many | collection of related models | `->type('tracks')` |
| `BelongsToMany` | to-many | pivot (join) table | `->type('playlists')` |
| `MorphTo` | to-one | polymorphic FK | `->types('tracks', 'albums', 'artists')` |
| `MorphToMany` | to-many | polymorphic collection | `->types('tracks', 'albums', 'artists')` |

By convention the relationship name is the domain property holding the related
object(s). `BelongsTo::make('artist')` reads `$album->artist`; `HasMany::make('tracks')`
reads `$album->tracks`. No foreign-key column, no extractor — the default reader
pulls the related value straight off the parent. You only reach for an override
when the related value is *derived* rather than stored (see
[Custom relation hooks](#custom-relation-hooks)).

## BelongsTo — a to-one foreign key

The common case. [`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php)
declares its artist:

```php
// src/Resource/AlbumResource.php
BelongsTo::make('artist')->type('artists'),
```

That single line renders `album.relationships.artist` with the artist's
identifier, the conventional `self` / `related` links, and — on `?include=artist`
— the full artist in `included`. The related read endpoint `GET /albums/1/artist`
returns the artist resource; the linkage endpoint
`GET /albums/1/relationships/artist` returns just its identifier (see
[related-endpoints](related-endpoints.md)).

When this relation appears in a write body, the default apply stores the parsed
linkage id on the field's column (`Mode::Replace` semantics). An empty to-one —
the related value is `null` — renders `data: null` rather than omitting the
member, which the linkage endpoint requires per the spec.

## HasOne — a to-one foreign key on the related model

`HasOne` extends `BelongsTo` and carries identical metadata; the distinction is
advisory for data-layer adapters (the FK lives on the *related* model, not the
owning one). [`ArtistResource`](../examples/music-catalog/src/Resource/ArtistResource.php)
declares one:

```php
// src/Resource/ArtistResource.php
HasOne::make('featuredAlbum')->type('albums'),
HasMany::make('albums')->type('albums')->linkageOnlyWhenLoaded(),
```

`GET /artists/1/featuredAlbum` returns the featured album; an artist with none
renders `data: null`.

## HasMany — a to-many collection

A to-many relation. [`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php)'s
tracks (the `PagePaginator` here is one of the paginators from
[pagination](pagination.md#pagepaginator--the-baseline); per-relation pagination
is covered [below](#per-relation-pagination)):

```php
// src/Resource/AlbumResource.php
use haddowg\JsonApi\Pagination\PagePaginator;

HasMany::make('tracks')
    ->type('tracks')
    ->paginate(PagePaginator::make()->withDefaultPerPage(2))
    ->linkageOnlyWhenLoaded(),
```

To-many relations add two cardinality bounds, `minItems()` / `maxItems()`, that
produce a `422` when a write supplies too few or too many members:

```php
HasMany::make('tracks')->type('tracks')->minItems(1)->maxItems(50),
```

On a to-many mutation the default apply maintains a **deduplicated id set** under
the `Mode` of the request (the `Mode` enum, see
[relationship-mutation](relationship-mutation.md)): `Mode::Replace` sets the whole
set, `Mode::Add` appends (idempotently), `Mode::Remove` subtracts. See
[relationship-mutation](relationship-mutation.md) for the endpoints that drive
those modes.

## BelongsToMany — a pivot-backed to-many

A to-many backed by a join table, which can carry its own pivot columns.
[`TrackResource`](../examples/music-catalog/src/Resource/TrackResource.php)'s
playlists:

```php
// src/Resource/TrackResource.php
BelongsToMany::make('playlists')
    ->type('playlists')
    ->fields(['position' => 'integer', 'addedAt' => 'datetime'])
    ->cannotReplace(),
```

`fields()` declares the pivot fields and accepts either an array or a closure
returning one:

```php
public function fields(\Closure|array $fields): static
```

In core 1.0 these are **declare-only** — carried as metadata, never validated by
core. The Symfony bundle's Doctrine adapter consumes them to write the join row.
Read them back with the `pivotFields()` accessor (which resolves the closure form).

`BelongsToMany` extends `HasMany`, so it inherits `minItems()` / `maxItems()` and
the deduplicated-set apply. The `cannotReplace()` above is a mutation gate covered
in [Mutation gates](#mutation-gates).

## MorphTo — a polymorphic to-one

A to-one whose related resource may be one of several types. Declare the allowed
types with `types()`; the related object's serializer is resolved at runtime from
its own `getType()`. [`FavoriteResource`](../examples/music-catalog/src/Resource/FavoriteResource.php):

```php
// src/Resource/FavoriteResource.php
MorphTo::make('favoritable')
    ->types('tracks', 'albums', 'artists')
    ->extractUsing(static fn(mixed $favorite): ?object => $favorite instanceof Favorite ? $favorite->favoritable : null),
```

The same endpoint shape renders a different resource depending on the favorite:
`GET /favorites/1/favoritable` resolves a track, `/favorites/2/favoritable` an
album, `/favorites/3/favoritable` an artist — each through the serializer the
related object's type reports. A null related value renders `data: null` (the
linkage is bound to the first declared, registered serializer so the relationship
still carries a resource).

> The `extractUsing()` here is the one custom reader in the example app — a
> polymorphic to-one is a natural place to need one when the related value is
> *derived* rather than a plain property. For a relation whose property matches its
> name, drop it. See [Custom relation hooks](#custom-relation-hooks).

## MorphToMany — a polymorphic to-many

A to-many collection whose members may each be a different type.
[`LibraryResource`](../examples/music-catalog/src/Resource/LibraryResource.php):

```php
// src/Resource/LibraryResource.php
MorphToMany::make('items')->types('tracks', 'albums', 'artists'),
```

The mixed members render through a single
[`PolymorphicSerializer`](serializers.md) decorator that resolves each member's
serializer against the declared types and delegates — so a collection of a track,
an album, and an artist each carries its own `type`. A member matching **no**
declared type throws a `\LogicException`.

A polymorphic to-many carries no shared filter/sort vocabulary across its mixed
member types, so `filter` and `sort` on its related collection return `400`,
though `page` still slices it. The reference Doctrine adapter does not support a
polymorphic to-many related endpoint (its members span entity classes) — supply a
custom provider there; see [related-endpoints](related-endpoints.md).

## Backing and advisory metadata

A relation shares the field backing surface and adds two advisory flags for
adapters:

| Method | Effect |
| --- | --- |
| `storedAs(string $column)` | Read/write the related value from a differently-named domain member than the relationship name. |
| `computed()` | No backing column; pair with `extractUsing()` to supply the related value. |
| `inverseType(string $name)` | Records the inverse relationship name on the related type. **Advisory** — metadata for adapters / OpenAPI generation. |
| `cannotEagerLoad()` | Hints that a data-layer adapter should not eager-load this relation. **Advisory** — core ships metadata only. |

## Conventional links

By default every relation emits the spec's conventional `self` and `related`
links, built from the owning resource's type + id and the relation's URI segment.
You opt out per relation with `withoutLinks()`:

```php
HasMany::make('tracks')->type('tracks')->withoutLinks(),
```

The URI segment defaults to the relation name; override it with
`withUriFieldName()` when the endpoint path should differ from the field name:

```php
BelongsTo::make('artist')->type('artists')->withUriFieldName('by'),
```

Links are gated by **endpoint exposure**: if you suppress a relation's endpoint
(below), the matching link is omitted so a rendered link never points at a `404`.

## linkageOnlyWhenLoaded

Linkage normally requires reading the related value to emit identifiers. For a
lazy storage relation that is an unwanted load just to serialize ids.
`linkageOnlyWhenLoaded()` opts a relation into a load-aware policy: when the
related value is **not** already loaded, emit the relationship object's `links`
only and omit `data`, rather than triggering a load.

```php
// src/Resource/AlbumResource.php
HasMany::make('tracks')
    ->type('tracks')
    ->linkageOnlyWhenLoaded(),
```

The policy is off by default and gated by an injected
`RelationshipLoadStateInterface` (the storage adapter reports load state). Three
override rules keep the output valid:

- **Included wins.** An `?include`d relationship always emits `data` (it has been
  loaded to be included).
- **`withoutLinks()` always emits data.** A relation with no links cannot omit
  `data` too, or it would render an empty relationship object — so it always emits
  linkage.
- **No load-state injected = treated as loaded.** With no
  `RelationshipLoadStateInterface` present (the standalone default), the relation
  emits data as normal.

## Endpoint exposure

Each relation exposes two HTTP endpoints by default — the related read
(`GET /{type}/{id}/{rel}`) and the relationship linkage
(`GET|PATCH|POST|DELETE /{type}/{id}/relationships/{rel}`). Suppress either one:

| Method | Effect |
| --- | --- |
| `withoutRelatedEndpoint()` | The host treats `GET /{type}/{id}/{rel}` as a `404` and omits the conventional `related` link. |
| `withoutRelationshipEndpoint()` | The host treats `…/relationships/{rel}` as a `404` and omits the conventional `self` link. |

In both cases the matching link is omitted so a rendered link never points at the
`404`. See [related-endpoints](related-endpoints.md) for the endpoints themselves.

## Mutation gates

Three flags gate what a relationship-mutation request may do (the full
replace / add / remove trio):

| Method | Prohibits | Thrown |
| --- | --- | --- |
| `cannotReplace()` | a `PATCH` to the relationship endpoint (and a to-one `data: null`, which is a removal) | `FullReplacementProhibited` (403) |
| `cannotRemove()` | a `DELETE` from a to-many endpoint, or clearing a to-one (`data: null`) | `RemovalProhibited` (403) |
| `cannotAdd()` | a `POST` to a to-many relationship endpoint | `AdditionProhibited` (403) |

```php
// src/Resource/TrackResource.php
BelongsToMany::make('playlists')
    ->type('playlists')
    ->cannotReplace(),
```

All three are allowed by default. See
[relationship-mutation](relationship-mutation.md) for how the endpoints map to
these gates.

## Per-relation pagination

A to-many relation paginates its related-collection endpoint with `paginate()`:

```php
// src/Resource/AlbumResource.php
HasMany::make('tracks')
    ->type('tracks')
    ->paginate(PagePaginator::make()->withDefaultPerPage(2)),
```

The host resolves the effective strategy as **relation → related resource →
server default**: a relation-level paginator wins, otherwise the related
resource's default, otherwise the server's. A to-one relation has no collection
and ignores it. See [pagination](pagination.md) for the paginators.

## Custom relation hooks

When the default reader does not fit — the related value is derived, not a plain
property — override the read/write:

| Method | Role |
| --- | --- |
| `extractUsing(\Closure $callback)` | Supplies the related domain value(s) instead of reading the property. |
| `fillUsing(\Closure $callback)` | Writes the parsed input relationship into the domain object instead of the default apply. |
| `readValue(mixed $model, $request)` | Public accessor: reads the related value(s) **without serializing**. |

`readValue()` is what a data layer drives the related and relationship endpoints
with — it hands the related domain value(s) to the related type's provider
without going through the serializer:

```php
public function readValue(mixed $model, JsonApiRequestInterface $request): mixed
```

The only `extractUsing()` in the example app is on the `favoritable` `MorphTo`
above, where the related object is picked off a discriminated member. Every other
relation uses the default reader — reach for these hooks only when you must.

## Next

- [related-endpoints](related-endpoints.md) — the related and relationship read
  endpoints, `?include`, and paginated related collections.
- [relationship-mutation](relationship-mutation.md) — `PATCH` / `POST` / `DELETE`
  on relationship endpoints and the `Mode` semantics.
- [serializers](serializers.md) — how a related resource serializes, and the
  `PolymorphicSerializer`.
- [fields](fields.md) — the shared builder surface a relation inherits.
- [pagination](pagination.md) — the paginators a relation's collection uses.
