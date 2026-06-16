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
    ->fields(
        Integer::make('position')->min(1),
        DateTime::make('addedAt')->readOnly(),
    )
    ->cannotReplace(),
```

`fields()` declares the pivot (join-table) fields as **real field definitions** —
the same field DSL you use for attributes (`Integer`, `Str`, `DateTime`, …) with
their constraints, casts and read-only / context behaviour:

```php
public function fields(FieldInterface ...$fields): static
public function pivotFields(): array              // list<FieldInterface>
public function pivotField(string $name): ?FieldInterface
public function writablePivotFields(bool $creating): array  // list<FieldInterface>
```

One declaration drives every pivot concern:

- **render** — the field's value cast applies to the raw pivot column, and the
  typed values render on each linkage member's `meta` under a `pivot` key (see
  below);
- **filter / sort** — the field's name + column become a `filter[…]` / `sort=`
  key on the related-collection endpoint;
- **write / validate** — the field's constraints validate the incoming `meta`,
  resolved by the operation's create vs update context exactly as for an attribute.

### Rendering pivot fields — the `meta.pivot` linkage shape

On read, each linkage member carries its pivot values under a **`pivot`** key in
the member's `meta` — namespaced so they never collide with the related
resource's own intrinsic meta, and so a client can tell pivot data from member
meta. The values are the field-cast pivot column values, keyed by pivot field
name. `GET /playlists/1/relationships/tracks` (a `BelongsToMany`) renders linkage
members shaped like:

```json
{
  "data": [
    {
      "type": "tracks", "id": "7",
      "meta": { "pivot": { "position": 3, "addedAt": "2026-01-01T00:00:00+00:00" } }
    }
  ]
}
```

The same `meta.pivot` rides the full related resource on the related read
(`GET /playlists/1/tracks`) — the pivot values sit in the member resource's
top-level `meta`, alongside any intrinsic meta the type's serializer emits. Core
stays storage-agnostic: it carries the field definitions, and the host adapter
(the Symfony bundle's Doctrine adapter) reads the join row and supplies the typed
values it renders under `pivot`.

### Writing pivot fields — the linkage `meta` convention

A pivot field is **writable by default**; opt a server-owned column out with
`->readOnly()` (or `->readOnlyOnUpdate()` / `->readOnlyOnCreate()` for a
context-scoped opt-out). Pivot values are written through the JSON:API
**resource-identifier `meta`** on each linkage member — the spec allows a
resource identifier to carry `meta`:

```http
POST /playlists/1/relationships/orderedTracks
{ "data": [ { "type": "tracks", "id": "7", "meta": { "position": 3 } } ] }
```

```http
PATCH /playlists/1/relationships/orderedTracks
{ "data": [
  { "type": "tracks", "id": "7", "meta": { "position": 1 } },
  { "type": "tracks", "id": "9", "meta": { "position": 2 } }
] }
```

The same per-member `meta` rides the relationship when it appears inline in a
whole-resource `POST` / `PATCH` body. `DELETE` (remove) carries no pivot.

The relevant readers:

- `writablePivotFields($creating)` returns the fields settable from `meta` in the
  given operation context (read-only ones filtered out by their context);
- each parsed linkage member exposes its `meta` on the
  [`ResourceIdentifier`](../src/Schema/ResourceIdentifier.php) value object
  (`$identifier->meta`) — on **both** the relationship-endpoint body and a
  relationship nested in a whole-resource body. No new wire-parsing surface was
  needed: a resource identifier has always parsed its `meta`.

Core stays **storage-agnostic**: it carries the field definitions and the parsed
`meta`, but never writes the join row. The Symfony bundle's Doctrine adapter is
the executor — it validates the `meta` against the writable pivot fields'
constraints (a violation is a `422` pointing at the linkage `meta`), and persists
the association entity as an **upsert / reorder diff** (update an existing row in
place, create a missing one, and on a full replace remove rows whose member is no
longer present). A read-only pivot field supplied in `meta` is never written. See
the bundle's relationships / Doctrine docs for the persistence details.

> **The Doctrine fact.** A plain `#[ORM\ManyToMany]` join table holds only the
> two foreign keys — Doctrine cannot map a `position` / `addedAt` column on it. To
> *have* pivot columns the join must be modelled as an **association entity**
> (`PlaylistTrack { int position; \DateTime addedAt; ManyToOne playlist; ManyToOne
> track }`), with the parent owning a `OneToMany` to it and the association entity
> a `ManyToOne` to the far type. The bundle's Doctrine adapter auto-detects this
> association entity from the parent's metadata.

When auto-detection is ambiguous (the parent has more than one to-many association
that could back the pivot) or finds nothing, name the association entity explicitly
with `through()` — an **opaque, declare-only** class-string that core carries but
never interprets (it stays storage-agnostic), and the host adapter reads as the
association entity backing the pivot:

```php
public function through(?string $associationEntity): static
public function pivotThrough(): ?string
```

```php
BelongsToMany::make('tracks')
    ->type('tracks')
    ->fields(
        Integer::make('position')->min(1),
        DateTime::make('addedAt')->readOnly(),
    )
    ->through(PlaylistTrack::class),
```

`pivotThrough()` reads it back (`null` when no override was declared). Passing
`null` clears an earlier override.

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

## Includability

A relation is includable in a compound document by default. Opt out with
`cannotBeIncluded()`: a `?include` naming it (at any path) is then a
`400 InclusionNotAllowed`, and it is dropped from the default-include cascade. Its
linkage and `self` / `related` links are unaffected.

```php
BelongsTo::make('internalNotes')->type('notes')->cannotBeIncluded(),
```

This is the per-relation half of the include safeguards; a root-scoped
allowed-include-paths whitelist and a maximum include depth are documented under
[sparse fieldsets and includes](sparse-fieldsets-and-includes.md#constraining-includes-the-safeguards).

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

## Countable relations and `?withCount`

A to-many relation can expose its cardinality as `meta.total` on the relationship
object — the same `total` semantic endpoint pagination uses. Opt a relation in
with `countable()`; read it back with `isCountable()`:

```php
// src/Resource/AlbumResource.php
HasMany::make('tracks')
    ->type('tracks')
    ->paginate(PagePaginator::make()->withDefaultPerPage(2))
    ->linkageOnlyWhenLoaded()
    ->countable(),
```

A client opts into the count per request with the flat, comma-separated
`?withCount` query parameter — `?withCount=tracks` (several relations:
`?withCount=tracks,playlists`). It is never dotted (a primary-request parameter,
like `?include` but un-nested) and carries an uppercase letter, so it is a valid
implementation-specific query parameter and is not rejected by strict
query-parameter validation. When the request names a countable relation —
`GET /albums/1?withCount=tracks` — its relationship object gains a `meta.total`:

```json
{
  "links": { "self": "…/albums/1/relationships/tracks", "related": "…/albums/1/tracks" },
  "meta": { "total": 3 },
  "data": [ { "type": "tracks", "id": "1" }, { "type": "tracks", "id": "2" }, { "type": "tracks", "id": "3" } ]
}
```

With **no** `?withCount` (or none naming this relation) the relationship object
carries no `meta` key at all. The meta key is exactly **`total`** — the same key
the count-based pages emit, so a relationship-object total and an endpoint
pagination total are one consistent semantic.

`countable()` is the single universal **count gate**, validated up front and
root-scoped: a `?withCount` naming a relation that is **not** `countable()` — or
naming a to-one relation, which has no cardinality — is a
`400 RelationshipCountNotAllowed` (`source.parameter: withCount`), mirroring
`InclusionNotAllowed`. A resource that declares no countable relations rejects any
`?withCount` against it; counting is opt-in.

Core never computes the count — it is storage-specific (a pushed-down `COUNT`, a
counted in-memory collection) and batched across a fetched page of parents to
avoid an N+1. The host supplies it through an injected
[`RelationshipCountInterface`](../src/Serializer/RelationshipCountInterface.php)
(`countRelationship($model, $relation): ?int`); with **no** resolver injected
(standalone core) no `meta.total` is emitted even for a countable,
`?withCount`-named relation. The countable flag also drives the related-collection
endpoint's pagination total (a non-countable relation's endpoint paginates
count-free — no `total`, no `last`). See [ADR
0057](adr/0057-countable-relations-and-count-free-pages.md) for the full design.

## Relation-scoped filters and sorts

A to-many relation can declare extra `filter`/`sort` keys that apply **only** to
its related-collection endpoint (`GET /{type}/{id}/{rel}`) — not the primary
collection of the related type. Declare them with `withFilters()` / `withSorts()`,
passing the same [`FilterInterface`](filters.md) / [`SortInterface`](sorts.md)
value objects a resource exposes:

```php
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;

// src/Resource/PlaylistResource.php
HasMany::make('tracks')
    ->type('tracks')
    ->withFilters(Where::make('genre'))
    ->withSorts(SortByField::make('title')),
```

The point is **scoping**. A filter or sort declared on the related *resource* is
exposed everywhere that type is listed — `/tracks` **and** `/playlists/1/tracks`.
Declaring it on the *relation* scopes it to that one related-collection endpoint:
the natural home for a contextual filter/sort (ordering a playlist's tracks by
their in-playlist position; a filter only meaningful when listing a user's
posts). The same key is **not** recognized on the primary `/{relatedType}`
collection — a request using it there `400`s (or simply isn't advertised), exactly
as for any unknown key.

The host merges a relation's `withFilters()`/`withSorts()` with the related
resource's own vocabulary, so both apply together on the related endpoint. On a
**key clash** (the same `filter`/`sort` key declared on both the related resource
and the relation) the **relation's** declaration wins — the more specific scope.
A key in *neither* set still `400`s as an unrecognized parameter.

> **Scope: the related entity, not the pivot.** A relation-scoped filter/sort
> targets a column on the **related entity** (the common case) — that works out of
> the box. A **pivot/join-table** filter/sort (e.g. a many-to-many `position`
> column on the join row, not on the related entity) is supported **only** via a
> custom `FilterHandler` / `SortHandler` you supply — the seam allows it, but the
> framework does not auto-wire join-table columns. Declare the metadata here and
> point it at your handler.

These vocabularies also drive the **relationship-queries profile**: a client that
negotiates it can filter and sort a relationship's *linkage* from the primary
request — `relatedQuery[<path>][filter][<key>]=…` / `[sort]=…` (shorthand `rQ`) —
against the same filter/sort keys declared here. See [profiles](profiles.md#the-bundled-relationship-queries-profile).

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
