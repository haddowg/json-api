# Serializers

A custom serializer gives you full control over how a domain object becomes a
JSON:API resource — for the read-side cases a [Resource class](resources.md)'s
[field declaration](fields.md) can't express. You implement
`Serializer\SerializerInterface` directly (or extend
`Serializer\AbstractSerializer`), then either register it as an *override* on a
type — replacing the Resource class's serialization while keeping its hydration —
or register it *standalone* under a type string with no Resource at all. This page
also covers the read side of polymorphism: rendering a mixed-type collection
through `PolymorphicSerializer`.

For the common case you never write one — a Resource class's `fields()` serializes
for you — so the escape hatches come in tiers, cheapest first.

> **A note on names.** "Resource" is overloaded. The class documented here is a
> *serializer* — `Serializer\SerializerInterface`, the lower-level serializer
> contract. It is **not** the JSON:API spec's *resource object* (the
> `{type, id, attributes, relationships}` structure inside `data`), which this
> package emits as a plain array rather than a class you write. It is also not the
> [Resource class](resources.md) (`Resource\AbstractResource`), the primary surface
> a custom serializer gives you a way around. See [Concepts](concepts.md).

## The escape-hatch tiers

Reach for the cheapest tier that covers your case:

1. **A single custom field** — keep the Resource class and attach a
   [`serializeUsing()` / `extractUsing()` hook](fields.md#the-four-hooks)
   to just that field. Use this whenever one member needs custom read logic.
2. **A whole custom serializer** — replace the type's serialization entirely.
   Reach for this **last**, only when serialization needs logic a field walk can't
   model.

## When to write a whole serializer

Three triggers justify a full serializer over a field hook:

- **Request-aware or conditional attributes** — a member that appears, changes
  shape, or is computed differently depending on the current request. The
  serializer receives the `JsonApiRequestInterface` for every attribute, so even
  the *set* of attributes can depend on the request.
- **Computed or derived values** that draw on several model members at once, or on
  data outside the model.
- **Multiple representations of one model** — the same domain object exposed as
  more than one resource type, registered under different serializers.

## The contract

`SerializerInterface` maps a domain value (`mixed` — an object, an array, or any
representation) to the parts of a JSON:API resource object. There are seven
methods:

| Method | Signature | Returns |
| --- | --- | --- |
| `getType` | `getType(mixed $object): string` | the resource `type` |
| `getId` | `getId(mixed $object): string` | the resource `id` |
| `getMeta` | `getMeta(mixed $object, JsonApiRequestInterface $request): array` | top-level `meta` for the resource |
| `getLinks` | `getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks` | the resource `links` (or `null`) |
| `getAttributes` | `getAttributes(mixed $object, JsonApiRequestInterface $request): array` | a **map of callables**, one per attribute |
| `getDefaultIncludedRelationships` | `getDefaultIncludedRelationships(mixed $object): array` | `list<string>` of relations to include by default |
| `getRelationships` | `getRelationships(mixed $object, JsonApiRequestInterface $request): array` | a **map of callables**, one per relationship |

```php
interface SerializerInterface
{
    public function getType(mixed $object): string;
    public function getId(mixed $object): string;

    /** @return array<string, mixed> */
    public function getMeta(mixed $object, JsonApiRequestInterface $request): array;

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks;

    /** @return array<string, callable(mixed, JsonApiRequestInterface, string): mixed> */
    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array;

    /** @return list<string> */
    public function getDefaultIncludedRelationships(mixed $object): array;

    /** @return array<string, callable(mixed, JsonApiRequestInterface, string): AbstractRelationship> */
    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array;
}
```

### Statelessness and maps of callables

The serializer is **stateless**: every method is a pure function of its arguments,
so a single instance safely serializes many objects — collection items and
recursively included resources alike. A resource's identity (`getType()` /
`getId()`) and its default includes depend only on the object; the request-shaped
members (`getMeta()` / `getLinks()` / `getAttributes()` / `getRelationships()`)
receive the request directly.

`getAttributes()` and `getRelationships()` return **maps of callables**, not
values: each callable receives the domain object, the request, and the member
name, and returns the value (or, for a relationship, an `AbstractRelationship`).
The engine invokes only the callables for members that survive
[sparse-fieldset](sparse-fieldsets-and-includes.md) filtering — declaring an expensive
attribute costs nothing unless the client actually asks for it. The request is
passed to `getAttributes()` / `getRelationships()` themselves as well, so the
*set* of members — not just each value — can depend on the request.

## A worked example

[`TrackSerializer`](../examples/music-catalog/src/Serializer/TrackSerializer.php)
is registered as a read override for the `tracks` type. It exercises two of the
three triggers: a **request-aware** `nowPlaying` (present only when the request
carries an authenticated user) and a **computed** `displayTitle` assembled across
two columns on read.

```php
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Serializer\AbstractSerializer;

// SerializerResolverAwareInterface is the opt-in for relationship rendering,
// covered in "Rendering relationships from a serializer" below; it is not needed
// for the request-aware/computed attributes this example is about.
final class TrackSerializer extends AbstractSerializer implements SerializerResolverAwareInterface
{
    public function getType(mixed $object): string
    {
        // Object-aware so a polymorphic resolver probing this serializer does not
        // falsely claim a foreign member as a `tracks` resource.
        return $object instanceof Track ? 'tracks' : '';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Track);

        return $object->id;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        $attributes = [
            'title' => static fn(mixed $track, JsonApiRequestInterface $request, string $field): string
                => $track instanceof Track ? $track->title : '',
            // …
            // Computed across two columns purely on read.
            'displayTitle' => static fn(mixed $track, JsonApiRequestInterface $request, string $field): string
                => $track instanceof Track ? \sprintf('%d. %s', $track->trackNumber, $track->title) : '',
        ];

        // Request-aware: `nowPlaying` exists ONLY for an authenticated user. The
        // attribute *set* is request-dependent — anonymous responses omit it.
        if ($request->getAttribute('user') !== null) {
            $attributes['nowPlaying'] = static fn(mixed $track, JsonApiRequestInterface $request, string $field): bool
                => $track instanceof Track && $request->getAttribute('nowPlayingTrackId') === $track->id;
        }

        return $attributes;
    }

    // …
}
```

Notice the callables guard their input with `$track instanceof Track` and `getType()`
returns `''` for a non-`Track`: the serializer keeps no per-pass state, and a
polymorphic resolver may probe it with a foreign object. Returning `''` from
`getType()` lets such a probe fall through to the right serializer rather than
falsely claiming the member.

## `AbstractSerializer` and `TransformerTrait`

[`AbstractSerializer`](../examples/music-catalog/src/Serializer/TrackSerializer.php)
is `SerializerInterface` plus the `Serializer\TransformerTrait` and nothing else —
the contract is stateless, so there is no per-pass machinery to inherit. The trait
gives you attribute-value formatting helpers:

| Method | Use |
| --- | --- |
| `toDecimal(mixed $value, int $precision = 12): float` | normalise a numeric value to a fixed precision |
| `toIso8601Date(\DateTimeInterface $dt, ?\DateTimeZone $tz = null): string` | a `\DateTimeInterface` to an ISO-8601 date |
| `toIso8601DateTime(\DateTimeInterface $dt, ?\DateTimeZone $tz = null): string` | a `\DateTimeInterface` to an ISO-8601 date-time |
| `fromSqlToIso8601Time(string $value, ?\DateTimeZone $tz = null): string` | a SQL timestamp string to ISO-8601 |
| `fromSqlToUtcIso8601Time(string $value): string` | a SQL timestamp string to UTC ISO-8601 |

The trait is public and independently composable: if you implement
`SerializerInterface` directly you can `use TransformerTrait` on your own class
rather than extend the base.

## Registering an override

Register the serializer alongside the Resource class with the `serializer:`
argument. The registry resolves the override ahead of the Resource class for
serialization and falls back to the Resource class for hydration, so you keep the
Resource class's field-driven writes — read and write are independently resolvable.
From [`bootstrap.php`](../examples/music-catalog/src/bootstrap.php):

```php
$server = Server::make()
    // …
    ->register(TrackResource::class, serializer: TrackSerializer::class);
```

> **Override serializers take no constructor arguments.** The registry
> instantiates an override with `new TrackSerializer()`. By default it does **not**
> inject the relationship `SerializerResolverInterface`, so an override is best
> suited to shaping `attributes` (request-aware, conditional, computed). When a
> type needs both related-resource serialization *and* attribute logic the field
> walk can't express, keep the [Resource class](resources.md) and override only the
> narrower concern — or opt in to the resolver (below).

For the full picture of override resolution and bare registration, see
[capability composition](capability-composition.md).

## Rendering relationships from a serializer

An override or standalone serializer renders relationships only if it accepts the
injected `SerializerResolverInterface`. Implement
[`SerializerResolverAwareInterface`](../examples/music-catalog/src/Serializer/TrackSerializer.php)
and the registry calls `setSerializerResolver()` after construction; without it,
`getRelationships()` has nothing to resolve related types against and returns `[]`.

`TrackSerializer` opts in and renders the same `album` and `playlists` relations
the Resource declares, through the shared `RendersRelationsTrait`. The trait
supplies one helper — `relationshipCallables(array $relations, SerializerResolverInterface $resolver): array` —
which turns a list of [relation fields](relations.md) into the callable map
`getRelationships()` returns. It does **not** supply the relations themselves: the
`relations()` it is handed is a method **you** write on the serializer, returning a
`list<RelationInterface>` (in
[`TrackSerializer`](../examples/music-catalog/src/Serializer/TrackSerializer.php)
it re-declares the same `album` / `playlists` the Resource declares). It is built
per call, since the contract is stateless:

```php
use haddowg\JsonApi\Resource\RendersRelationsTrait;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;

final class TrackSerializer extends AbstractSerializer implements SerializerResolverAwareInterface
{
    use RendersRelationsTrait;

    private ?SerializerResolverInterface $serializerResolver = null;

    public function setSerializerResolver(SerializerResolverInterface $resolver): void
    {
        $this->serializerResolver = $resolver;
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        $resolver = $this->serializerResolver;
        if ($resolver === null) {
            return [];
        }

        return self::relationshipCallables($this->relations(), $resolver);
    }

    // …
}
```

`getDefaultIncludedRelationships()` returns the relations to include by default
(`['album']` would default-include the album); the default-include lever lives on
the serializer contract, not on a fluent field method. See
[sparse fieldsets & includes](sparse-fieldsets-and-includes.md) for how includes and default-includes flow.

## A standalone read-only serializer

A serializer can stand alone with no Resource and no hydrator — a read-only type.
[`ChartSerializer`](../examples/music-catalog/src/Serializer/ChartSerializer.php)
is registered by **type string**, not class:

```php
$server = Server::make()
    // …
    ->registerSerializerHydrator('charts', serializer: ChartSerializer::class);
```

Because nothing else is registered for `charts`, `hasSerializerFor('charts')` is
true but `hasHydratorFor('charts')` is false, and `resourceFor('charts')` throws
`NoResourceRegistered` — the read/write resolver mirror and the boundary that proves
read and write are decoupled. See [capability composition](capability-composition.md)
for the full standalone story.

`ChartSerializer` has no relations, so its `getRelationships()` returns `[]`. A
standalone serializer that *does* render relations declares them exactly as the
override above does — there is no Resource to lean on, but none is needed: the
`relations()` list is hand-written on the serializer itself. Implement
`SerializerResolverAwareInterface`, `use RendersRelationsTrait`, and return
`relationshipCallables($this->relations(), $resolver)` from `getRelationships()`,
with your own `relations()` supplying the `list<RelationInterface>`.

A standalone serializer can also declare its own URL segment by implementing
[`UriTypeAwareInterface`](../examples/music-catalog/src/Serializer/ChartSerializer.php):

```php
use haddowg\JsonApi\Serializer\UriTypeAwareInterface;

final class ChartSerializer extends AbstractSerializer implements UriTypeAwareInterface
{
    public function uriType(): string
    {
        return 'charts';
    }

    // …
}
```

This is the same URL-segment decoupling already taught for `AbstractResource`'s
`$uriType` (see [resources](resources.md#uritype--the-url-segment-decoupled-from-the-type)),
exposed here as an interface a standalone serializer implements directly.
`uriType()` is the URI path segment, decoupled from the JSON:API `type` member: a
type whose `getType()` is `book` can live at `/books`. A serializer that does not
implement the interface falls back to `getType()` as the segment.

## Polymorphic serialization (the read side of polymorphism)

A polymorphic relation — [`MorphTo`](relations.md) (to-one) or
[`MorphToMany`](relations.md) (to-many) — points at members of *different* types.
On read, each member must be rendered by *its own* serializer. `PolymorphicSerializer`
is the decorator that makes this work: it wraps a closure that resolves the right
serializer for a given member object and delegates every one of the seven methods
to it, so each member carries its own correct `type` / `id` / attributes.

```php
final class PolymorphicSerializer implements SerializerInterface
{
    /** @param \Closure(mixed): SerializerInterface $serializerFor */
    public function __construct(private readonly \Closure $serializerFor) {}

    public function getType(mixed $object): string
    {
        return $this->for($object)->getType($object);
    }

    // … every method delegates to $this->for($object) …

    private function for(mixed $object): SerializerInterface
    {
        return ($this->serializerFor)($object);
    }
}
```

The per-member resolution typically runs through `RelationInterface::resolveSerializer()`,
which picks the declared type whose serializer reports the object's own `getType()`:

```php
public function resolveSerializer(mixed $related, SerializerResolverInterface $resolver): ?SerializerInterface;
```

For a monomorphic relation it returns the single declared type's serializer; for a
`MorphTo` it discriminates by the object's type; for a `null` related value it
returns the first declared, registered serializer (the caller renders `data: null`).
It returns `null` when the relation declares no registered type, or — polymorphic —
when no declared type matches the object.

### Worked: `favoritable` (MorphTo) and `items` (MorphToMany)

The
[`FavoriteResource`](../examples/music-catalog/src/Resource/FavoriteResource.php)
declares a `MorphTo` to-one — `favoritable` points at a track, album, or artist:

```php
MorphTo::make('favoritable', ['tracks', 'albums', 'artists'])
    ->extractUsing(static fn(mixed $favorite): ?object => $favorite instanceof Favorite ? $favorite->favoritable : null),
```

`GET /favorites/1/favoritable` resolves the member's serializer from the related
object's own type at runtime, so the same endpoint shape renders a `tracks`,
`albums`, or `artists` resource depending on the favorite — verified in
[`PolymorphicTest`](../examples/music-catalog/tests/PolymorphicTest.php).

The [`LibraryResource`](../examples/music-catalog/src/Resource/LibraryResource.php)
declares a `MorphToMany` to-many — `items` is a mixed collection rendered through
`PolymorphicSerializer`:

```php
MorphToMany::make('items', ['tracks', 'albums', 'artists']),
```

`GET /libraries/1/items` returns tracks, albums, and artists in one collection,
each member carrying its own `type` and attributes. A member matching no declared
type throws a `\LogicException`. The polymorphic to-many carries no shared
filter/sort vocabulary, so `?filter[…]` and `?sort=…` return `400` (see
[errors & exceptions](errors-and-exceptions.md)), but `?page[…]` slices the mixed
collection. The relationship endpoint `GET /libraries/1/relationships/items`
renders the mixed linkage (type + id only, no attributes).

See [related endpoints](related-endpoints.md) for the endpoint-level behaviour of
these relations.

## Next / See also

- [Hydrators](hydrators.md) — the matching write-side customisation point, and
  relationship-write hydration.
- [Sparse fieldsets & includes](sparse-fieldsets-and-includes.md) — sparse
  fieldsets and `include`, which the maps-of-callables design serves.
- [Capability composition](capability-composition.md) — override resolution and
  standalone serializer/hydrator registration in full.
- [Relations](relations.md) — declaring `MorphTo` / `MorphToMany` and the rest of
  the relation vocabulary.
- [Resources](resources.md) — the field DSL a custom serializer gives you a way
  around.
