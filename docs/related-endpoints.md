# Related and relationship read endpoints

Every relationship you declare exposes two read endpoints: a **related read**
(`GET /{type}/{id}/{rel}`) that returns the full related resource(s), and a
**relationship read** (`GET /{type}/{id}/relationships/{rel}`) that returns just
the linkage — `type` + `id` identifiers, no attributes. This page shows how to
serve both, including the paginated, polymorphic, and empty-to-one cases.

The two endpoints answer different questions. The related read is "give me the
artist of this album"; the relationship read is "which artist *id* does this
album point at". You choose between them by URL, and the library renders each
through a different response value object.

> New here? Start with [getting-started](getting-started.md) and
> [relations](relations.md). Installation is covered in [index.md](index.md).

## Two endpoints, two operations, two responses

Both endpoints map to a no-body operation that carries the parent's
[`Target`](operations.md) (type, id, relationship name) plus the request's
[query parameters](sparse-fieldsets-and-includes.md):

| Endpoint | Operation | Response VO | `data` |
| --- | --- | --- | --- |
| `GET /{type}/{id}/{rel}` | [`FetchRelatedOperation`](../src/Operation/FetchRelatedOperation.php) | [`RelatedResponse`](responses.md) | the full related resource(s) |
| `GET /{type}/{id}/relationships/{rel}` | [`FetchRelationshipOperation`](../src/Operation/FetchRelationshipOperation.php) | [`IdentifierResponse`](responses.md) | linkage identifiers only |

Your [handler](operations.md) dispatches on the operation type. The worked
handler in the example app —
[`MusicCatalogHandler`](../examples/music-catalog/src/Handler/MusicCatalogHandler.php) —
handles both in one `match (true)`:

```php
$operation instanceof FetchRelatedOperation => $this->fetchRelated($operation),
$operation instanceof FetchRelationshipOperation => $this->fetchRelationship($operation),
// …
```

## The related read

For a related read you load the parent, resolve the named relation, read the
related value off the parent, and render it through the **related** type's
serializer. The single-resource form uses
[`RelatedResponse::fromResource()`](../src/Response/RelatedResponse.php):

```php
$parent = $this->loadParent($type, $target->id);
// …
$relation = $server->resourceFor($type)->relationNamed($relationshipName);
if ($relation === null || !$relation->exposesRelatedEndpoint()) {
    return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
}

$related = $relation->readValue($parent, $request);
// …
$serializer = $relation->resolveSerializer($related, $server) ?? $server->serializerFor($relatedType);

return RelatedResponse::fromResource($related, $serializer);
```

`GET /albums/1/artist` returns the artist resource in full:

```json
{ "data": { "type": "artists", "id": "1", "attributes": { "name": "Radiohead" } } }
```

The links on a `RelatedResponse` are scoped to the related URL the client hit
(`/albums/1/artist`), not to a primary `/artists` collection.

### A to-many related read

When the relation is to-many, render the related list with
[`RelatedResponse::fromCollection()`](../src/Response/RelatedResponse.php) (or
`fromPage()` when it paginates — see below). `GET /albums/2/tracks` returns the
tracks of album 2:

```json
{ "data": [ { "type": "tracks", "id": "4", "attributes": { /* … */ } } ] }
```

### An empty to-one renders `data: null`

A to-one relation with no related object renders `data: null`, not a 404 — the
relationship exists, it is just empty. Radiohead's `featuredAlbum` is set, but
Portishead's is not:

```php
// GET /artists/2/featuredAlbum → Portishead has no featuredAlbum.
self::assertNull(JsonApiDocument::of($response)->data());
```

```json
{ "data": null }
```

In the handler this falls out naturally: `readValue()` returns `null`,
`resolveSerializer()` falls back to the first registered serializer, and
`RelatedResponse::fromResource(null, $serializer)` renders `data: null`.

## The relationship (linkage) read

The relationship read returns only identifiers. You route the **parent** through
the **parent's** serializer, naming the relationship, with
[`IdentifierResponse::forRelationship()`](../src/Response/IdentifierResponse.php):

```php
$relation = $server->resourceFor($type)->relationNamed($relationshipName);
if ($relation === null || !$relation->exposesRelationshipEndpoint()) {
    return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
}

return IdentifierResponse::forRelationship($parent, $server->serializerFor($type), $relationshipName);
```

`forRelationship(parent, parentSerializer, relName)` transforms the parent with
the relationship name as the requested relationship, so the transformer emits
linkage. `GET /albums/1/relationships/artist` returns one identifier with no
attributes:

```json
{ "data": { "type": "artists", "id": "1" } }
```

`GET /albums/1/relationships/tracks` returns a list of identifiers — three
`{ "type": "tracks", "id": … }` objects, none carrying `attributes`.

## Paginated related collections

A to-many related collection paginates per relation. The album→tracks relation
declares a paginator in [`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php):

```php
HasMany::make('tracks')
    ->type('tracks')
    ->paginate(PagePaginator::make()->withDefaultPerPage(2)),
```

The handler resolves the paginator with a three-step fallback — the **relation's**
paginator, else the **related resource's**, else the **server** default — and
renders with [`RelatedResponse::fromPage()`](../src/Response/RelatedResponse.php):

```php
$paginator = $relation->pagination()
    ?? $relatedResource?->pagination()
    ?? $server->defaultPaginator();
// …
if ($result instanceof PageInterface) {
    return RelatedResponse::fromPage($result, $serializer);
}
```

Album 1 has three tracks, so `GET /albums/1/tracks` returns a first page of two,
with `meta.page.total` of three and `next`/`last` links scoped to
`/albums/1/tracks`:

```php
self::assertCount(2, $this->collection($response));
self::assertSame(3, $page['total'] ?? null);
self::assertStringContainsString('/albums/1/tracks', $this->href($links['next']));
```

`fromPage()` paginates the related collection exactly as
[`DataResponse::fromPage()`](responses.md) paginates a primary collection — same
`links.{first,prev,self,next,last}` and `meta.page`, scoped to the related URL.
See [pagination](pagination.md) for the per-relation resolution in full.

## Polymorphic related endpoints

A polymorphic relation (`MorphTo` / `MorphToMany`) renders through the **same**
`FetchRelated` / `FetchRelationship` operations and the same response VOs — the
polymorphism is resolved in the **serializer**, not the operation.

### `MorphTo`: a polymorphic to-one

[`FavoriteResource`](../examples/music-catalog/src/Resource/FavoriteResource.php)
declares `favoritable` as a `MorphTo` over three types:

```php
MorphTo::make('favoritable')
    ->types('tracks', 'albums', 'artists')
    ->extractUsing(static fn(mixed $favorite): ?object => $favorite instanceof Favorite ? $favorite->favoritable : null),
```

The to-one resolves its serializer **from the related object** via
[`RelationInterface::resolveSerializer()`](../src/Resource/Field/RelationInterface.php),
so the same endpoint shape renders a different type per favorite:

```php
$serializer = $relation->resolveSerializer($related, $server) ?? $server->serializerFor($relatedType);

return RelatedResponse::fromResource($related, $serializer);
```

`GET /favorites/1/favoritable` resolves a track, `/favorites/2/favoritable`
resolves an album, `/favorites/3/favoritable` resolves an artist — each carrying
its own `type`. The relationship read picks up the resolved type in the linkage:
`GET /favorites/2/relationships/favoritable` yields `{ "type": "albums", "id": "1" }`.

### `MorphToMany`: a polymorphic to-many

[`LibraryResource`](../examples/music-catalog/src/Resource/LibraryResource.php)
declares `items` as a `MorphToMany`. The handler detects a polymorphic relation
(more than one related type) and renders its mixed members through a
[`PolymorphicSerializer`](serializers.md) decorator that resolves the per-member
serializer:

```php
$relatedTypes = $relation->relatedTypes();
$polymorphic = \count($relatedTypes) > 1;
// …
$serializer = $polymorphic
    ? $this->polymorphicSerializer($relation, $server)
    : $server->serializerFor($relatedType);
```

```php
private function polymorphicSerializer(RelationInterface $relation, Server $server): PolymorphicSerializer
{
    return new PolymorphicSerializer(
        static fn(mixed $object): SerializerInterface => $relation->resolveSerializer($object, $server)
            ?? throw new \LogicException(/* … */),
    );
}
```

`GET /libraries/1/items` returns a track, an album, and an artist, each with its
own `type`. The relationship read renders mixed linkage the same way.

A polymorphic to-many carries **no shared filter or sort vocabulary** — the
members span entity classes, so there is no common column to filter or sort by.
The handler rejects either with a `400`, but `page` still slices the mixed list:

```php
if ($polymorphic) {
    $unsupported = match (true) {
        $operation->queryParameters()->filter !== [] => 'filter',
        $operation->queryParameters()->sort !== [] => 'sort',
        default => null,
    };
    if ($unsupported !== null) {
        return ErrorResponse::fromException(new \haddowg\JsonApi\Exception\QueryParamUnrecognized($unsupported));
    }
}
```

```php
// page slices the mixed collection even though filter/sort cannot.
$response = $this->get('/libraries/1/items?page[number]=1&page[size]=2');
self::assertCount(2, $this->collection($response));

$this->get('/libraries/1/items?filter[title]=anything'); // → 400
$this->get('/libraries/1/items?sort=title');             // → 400
```

> The in-memory provider supports the polymorphic mixed read (it reads the mixed
> collection off the parent). The Symfony bundle's Doctrine provider throws
> "unsupported" for a polymorphic to-many — its members span entity classes, so
> there is no single repository to scope — and you supply a custom provider.

## Endpoint exposure

By default both endpoints exist for every relation. Suppress one at declaration
time with `withoutRelatedEndpoint()` or `withoutRelationshipEndpoint()` (see
[relations](relations.md)). The handler enforces a suppressed endpoint as a
`404` and the matching `links` member is omitted, so a hidden endpoint is hidden
both at the URL and in the linkage. The guards in the worked handler are
`exposesRelatedEndpoint()` and `exposesRelationshipEndpoint()`:

```php
if ($relation === null || !$relation->exposesRelatedEndpoint()) {
    return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
}
```

## Compound includes

Both endpoints honour `?include`, so you can pull a related resource and its own
relations in one request. `GET /albums/1/artist?include=albums` returns the
related artist plus that artist's albums in `included`:

```php
$response = $this->get('/albums/1/artist?include=albums');
JsonApiDocument::of($response)
    ->assertHasType('artists')
    ->assertHasIncluded('albums');
```

See [sparse-fieldsets-and-includes](sparse-fieldsets-and-includes.md) for the
full inclusion and sparse-fieldset rules.

## Filtering a relationship's linkage from the primary request

These endpoints filter and sort their related collection with the plain
`filter[…]` / `sort=` parameters. To filter or sort a relationship's *linkage*
from a **primary** request instead — whether it renders via `?include`, as
links-only linkage, or at its own endpoint — negotiate the
**relationship-queries profile**: `relatedQuery[<path>][filter][<key>]=…` /
`[sort]=…` (shorthand `rQ`), keyed by the relationship's include path. See
[profiles](profiles.md#the-bundled-relationship-queries-profile) and the
[profile specification](profiles/relationship-queries.md).

## Next / See also

- [relations](relations.md) — declaring relations, linkage rendering, and
  endpoint exposure.
- [relationship-mutation](relationship-mutation.md) — the write twin:
  `PATCH`/`POST`/`DELETE .../relationships/{rel}`.
- [responses](responses.md) — `RelatedResponse`, `IdentifierResponse`, and the
  shared withers.
- [pagination](pagination.md) — the per-relation paginator resolution.
- [serializers](serializers.md) — the `PolymorphicSerializer` decorator.
