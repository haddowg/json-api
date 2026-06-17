# Mutating relationships

The relationship endpoints let a client change a resource's linkage without
touching its attributes: add a track to an album, swap an album's artist, drop a
member from a set. This page covers the three verbs that drive those endpoints,
the gates that reject a forbidden mutation with a `403`, and how the same write
path runs whether the linkage arrives at `/relationships/{rel}` or embedded in a
whole-resource write.

If you only need to *read* a relationship, see [related endpoints](related-endpoints.md);
to declare a relation in the first place, see [relations](relations.md).

## The verb trio

A JSON:API relationship endpoint accepts three verbs, and each maps to a
[`Mode`](../src/Resource/Field/Mode.php):

| Verb | Operation | Mode | Meaning |
| --- | --- | --- | --- |
| `PATCH /{type}/{id}/relationships/{rel}` | `UpdateRelationshipOperation` | `Mode::Replace` | replace the whole linkage with the supplied set |
| `POST /{type}/{id}/relationships/{rel}` | `AddToRelationshipOperation` | `Mode::Add` | append the supplied members to the existing set (to-many only) |
| `DELETE /{type}/{id}/relationships/{rel}` | `RemoveFromRelationshipOperation` | `Mode::Remove` | subtract the supplied members from the existing set (to-many only) |

The router builds one of those three [operation](operations.md) value objects and
hands it to your handler. Each carries `target()` (the parent type, id, and
relationship name), `queryParameters()`, `context()`, and `body()` (the linkage
document). The [`Mode`](../src/Resource/Field/Mode.php)
enum is the single vocabulary the apply path speaks — `Replace`, `Add`, `Remove`
— so a handler routes all three verbs through one method by passing the matching
mode:

```php
// MusicCatalogHandler::handle()
$operation instanceof UpdateRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Replace),
$operation instanceof AddToRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Add),
$operation instanceof RemoveFromRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Remove),
```

## The replace / add / remove trio, worked

These run against `albums → tracks`, a to-many declared on
[`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php) with
no mutability flags — so every verb is open. Album 1 is seeded with tracks 1, 2,
and 3. Each endpoint returns the relationship's *linkage* (resource identifiers
only) on success.

**`PATCH` replaces the whole set.** The body's linkage becomes the entire
relationship; everything not in it is dropped.

```php
// RelationshipMutationTest::patchReplacesTheWholeToManyLinkage()
$response = $this->patch('/albums/1/relationships/tracks', [
    'data' => [
        ['type' => 'tracks', 'id' => '4'],
    ],
]);

self::assertSame(200, $response->getStatusCode());
self::assertSame(['4'], $this->linkageIds($response)); // replace swaps the entire set
```

**`POST` adds without duplicating.** New members join the existing set; re-adding
a member already present is a no-op, so the id set stays deduplicated.

```php
// RelationshipMutationTest::postAddsToTheToManyLinkageWithoutDuplicating()
$response = $this->post('/albums/1/relationships/tracks', [
    'data' => [
        ['type' => 'tracks', 'id' => '4'],
        ['type' => 'tracks', 'id' => '1'], // already present — not duplicated
    ],
]);

self::assertSame(['1', '2', '3', '4'], $this->linkageIds($response));
```

**`DELETE` removes from the set.** The body names the members to drop; the rest
remain.

```php
// RelationshipMutationTest::deleteRemovesFromTheToManyLinkage()
$response = $this->delete('/albums/1/relationships/tracks', [
    'data' => [
        ['type' => 'tracks', 'id' => '1'],
    ],
]);

self::assertSame(['2', '3'], $this->linkageIds($response));
```

All three return `200` with the new linkage in the example, because the handler
re-reads and renders the mutated relationship; a handler that returns no body
would render `204` instead (see [responses](responses.md)). A mutation persists —
re-reading the relationship endpoint afterwards shows the change stuck:

```php
// RelationshipMutationTest::aMutatedLinkagePersistsAndIsReadableBack()
$this->patch('/albums/1/relationships/tracks', [
    'data' => [
        ['type' => 'tracks', 'id' => '4'],
        ['type' => 'tracks', 'id' => '2'],
    ],
]);

$read = $this->get('/albums/1/relationships/tracks');
self::assertSame(['4', '2'], $this->linkageIds($read));
```

## How linkage applies

The body of a relationship request is a *linkage* document — resource identifiers
(`type` + `id`), never full resources. Core parses it for you off the request:
`getRelationshipDataToMany($name)` yields a `ToManyRelationship`,
`getRelationshipDataToOne($name)` a `ToOneRelationship`. These read the
**top-level** `data` of a relationship-endpoint body (where `data` *is* the
linkage); they are distinct from `getToManyRelationship($name)` /
`getToOneRelationship($name)`, the pair a whole-resource write uses to read a
named relationship out of the `data.relationships.{name}.data` path. What you do
with that linkage is storage-specific, so it is the handler's (or a
[hydrator](hydrators.md)'s) job to turn the supplied ids into the parent's stored
relationship.

In the object-graph example app, the store holds related *objects*, so the
handler resolves each linkage id back to the stored object and sets the parent's
reference property:

```php
// MusicCatalogHandler::applyRelationship() — the single apply seam
if ($linkage instanceof ToOneRelationship) {
    $parent->{$property} = $linkage->resourceIdentifier?->id !== null
        ? $this->repository->fetchOne($relatedType, $linkage->resourceIdentifier->id)
        : null;

    return;
}

$parent->{$property} = $this->applyToMany($parent, $property, $relatedType, $linkage, $mode);
```

The crucial property is that **this is one seam, reused.** A relationship embedded
in a whole-resource write (`PATCH /albums/1` with a `relationships` member) lands
on exactly the same `applyRelationship()` call — the only difference is the mode
is always `Replace` for an embedded relationship:

```php
// RelationshipMutationTest::aRelationshipEmbeddedInAWholeResourceWriteIsApplied()
$response = $this->patch('/albums/1', [
    'data' => [
        'type' => 'albums',
        'id' => '1',
        'relationships' => [
            'artist' => ['data' => ['type' => 'artists', 'id' => '2']],
        ],
    ],
]);

JsonApiDocument::of($response)->assertHasRelationship('artist', 'artists', '2');
```

For how a whole-resource write strips, collects, and re-applies its relationships
around the attribute hydrator, see [hydrators (whole-resource writes)](hydrators.md).

## Mutation gates and their 403s

A relation can forbid any of the three mutations. You declare the restriction
fluently when building the relation field; core checks it before any apply runs
and throws a typed `403`. The example marks `tracks → playlists` non-replaceable
on [`TrackResource`](../examples/music-catalog/src/Resource/TrackResource.php):

```php
// TrackResource::fields()
BelongsToMany::make('playlists')
    ->type('playlists')
    ->fields(
        Integer::make('position')->min(1),
        DateTime::make('addedAt')->readOnly(),
    )
    ->cannotReplace();
```

| Fluent setter | Predicate | Mode gated | Exception (all `403`) | Error `code` |
| --- | --- | --- | --- | --- |
| `cannotReplace()` | `allowsReplace()` | `Replace` | `FullReplacementProhibited` | `FULL_REPLACEMENT_PROHIBITED` |
| `cannotAdd()` | `allowsAdd()` | `Add` | `AdditionProhibited` | `ADDITION_PROHIBITED` |
| `cannotRemove()` | `allowsRemove()` | `Remove` | `RemovalProhibited` | `REMOVAL_PROHIBITED` |

A `PATCH` to the prohibited relation is rejected; the other two verbs still work:

```php
// RelationshipMutationTest::replaceIsProhibitedOnACannotReplaceRelation()
$response = $this->patch('/tracks/1/relationships/playlists', [
    'data' => [['type' => 'playlists', 'id' => '00000000-0000-4000-8000-000000000001']],
]);

self::assertSame(403, $response->getStatusCode());
JsonApiErrors::of($response)
    ->assertHasError(status: '403', code: 'FULL_REPLACEMENT_PROHIBITED');
```

```php
// RelationshipMutationTest::addIsStillAllowedOnACannotReplaceRelation()
$response = $this->post('/tracks/3/relationships/playlists', [
    'data' => [['type' => 'playlists', 'id' => '00000000-0000-4000-8000-000000000001']],
]);

self::assertSame(200, $response->getStatusCode()); // cannotReplace() gates only Replace
```

**A to-one clear counts as a removal.** On a to-one relationship, `PATCH` with a
non-null linkage is a *replacement* (gated by `cannotReplace()`), while `PATCH`
with `data: null` clears the relationship — and clearing is a *removal* (gated by
`cannotRemove()`). So a to-one with `cannotRemove()` rejects `data: null` with
`RemovalProhibited`, while still accepting a re-point.

These gates are not specific to the relationship endpoints. Core applies the same
check inside [`AbstractResource::hydrateRelationship()`](../src/Resource/AbstractResource.php),
so if you drive mutation through the resource rather than owning the object-graph
write yourself, the `403`s come for free — see [hydrators](hydrators.md).

## Cardinality enforcement

Two more rejections protect the shape of the request, both checked before any
gate or apply:

- **Add or remove against a to-one** is a cardinality error. `POST` and `DELETE`
  on a relationship endpoint are *to-many* operations (append to / subtract from a
  set), so directing them at a to-one relation — even an open one — is a `400`
  [`RelationshipTypeInappropriate`](../src/Exception/RelationshipTypeInappropriate.php).

  ```php
  // RelationshipMutationTest::addToAToOneRelationshipEndpointIsACardinalityError()
  $response = $this->post('/albums/1/relationships/artist', [
      'data' => ['type' => 'artists', 'id' => '2'],
  ]);

  self::assertSame(400, $response->getStatusCode());
  ```

  `PATCH` *is* allowed on a to-one — that is how you re-point it:

  ```php
  // RelationshipMutationTest::patchReplacesAToOneRelationship()
  $response = $this->patch('/albums/1/relationships/artist', [
      'data' => ['type' => 'artists', 'id' => '2'],
  ]);

  self::assertSame(200, $response->getStatusCode());
  // the linkage now points at artist 2
  ```

- **An unknown relation** is a `404` `RelationshipNotExists` — there is no such
  relationship to mutate. (A to-many *body* against a to-one relation, by
  contrast, surfaces as the same cardinality `RelationshipTypeInappropriate`.)

## Empty linkage clears

An empty linkage means "set the relationship to nothing", in both cardinalities:

- a to-one with `data: null` clears the single reference (and counts as a
  removal, per the gate above);
- a to-many `PATCH` with `data: []` replaces the set with the empty set,
  emptying the relationship.

In the apply seam this falls out naturally — a null to-one identifier sets the
parent's property to `null`, and a `Replace` over an empty linkage resolves to an
empty list.

## Where the write lands

There are two places the mutation can actually happen, and you pick one per type:

1. **Through the resource / a hydrator.** `AbstractResource::hydrateRelationship($relationship, $request, $domainObject)`
   maps the verb to a `Mode`, enforces cardinality and the mutability gates, then
   applies the storage-agnostic baseline. A type with a hand-written
   [hydrator](hydrators.md) (the `UpdateRelationshipHydratorInterface` path)
   overrides the apply to mutate the real association. This is the path to take
   when the relationship write is expressible without reaching outside the domain
   object.

2. **In the handler.** When applying the linkage needs something the hydrator
   has no access to — in the example, resolving a linkage id to a stored related
   *object* needs the store — the handler owns the apply. The
   [`PlaylistHydrator`](../examples/music-catalog/src/Hydrator/PlaylistHydrator.php)
   declares no relationship hydrator (`getRelationshipHydrator()` returns `[]`)
   for exactly this reason, and the [`MusicCatalogHandler`](../examples/music-catalog/src/Handler/MusicCatalogHandler.php)
   re-applies the same cardinality and gate checks before its own object-graph
   write. Either way the typed exceptions propagate to the error handler, so you
   never catch them yourself.

## Next

- [Related endpoints](related-endpoints.md) — reading the related resources and
  linkage these verbs mutate.
- [Hydrators](hydrators.md) — the resource / hydrator apply path and the
  `UpdateRelationshipHydratorInterface` seam.
- [Hydrators (whole-resource writes)](hydrators.md) — relationships embedded in a whole-resource create/update.
- [Errors and exceptions](errors-and-exceptions.md) — the full typed-exception
  catalogue, including the `403`/`400`/`404` raised here.
