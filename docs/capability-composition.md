# Composing a type from independent capabilities

A JSON:API type is not one object. It is a set of independent capabilities —
a **serializer** (reads), a **hydrator** (writes), **relations**, and, in a host
integration, a provider and a persister — and you can supply any combination. This
page shows how to keep [`AbstractResource`](resources.md) and override just one
concern, and how to skip it entirely and register a bare serializer or hydrator
under a type string with no Resource at all.

If you are still on the [`AbstractResource`](resources.md) on-ramp, start there;
come back when you need a hand-written serializer or hydrator, a read-only or
write-only type, or a type with no Resource class.

## The thesis: `AbstractResource` is sugar

[`AbstractResource`](resources.md) is convenience, not architecture. It implements
the serializer contract, the hydrator contract, the relationship-write contract,
and the URI-segment contract, and pulls in the relation-rendering trait — all in
one class:

```php
abstract class AbstractResource implements
    SerializerInterface,
    HydratorInterface,
    UpdateRelationshipHydratorInterface,
    UriTypeAwareInterface,
    SerializerResolverAwareInterface
{
    use RendersRelationsTrait;
    // …
}
```

So when you `register(AlbumResource::class)`, the registry uses one object as the
serializer, the hydrator, the relation source, and the URI-segment authority for
the `albums` type. That bundling is the whole value of `AbstractResource`. It is
also the thing you opt out of, one concern at a time, when a concern outgrows the
field DSL.

There are two levers:

| You want to… | Use |
| --- | --- |
| Keep the Resource, replace one concern | `register(Resource::class, serializer: …)` / `register(…, hydrator: …)` |
| Skip the Resource entirely | `registerSerializerHydrator(type, serializer: …, hydrator: …)` |

## Override one concern, keep the Resource

`Server::register()` takes optional `serializer:` and `hydrator:` overrides
alongside the Resource class:

```php
public function register(
    string $resource,
    ?string $serializer = null,
    ?string $hydrator = null,
): self
```

A supplied override wins **per concern** — the registry resolves the override
first and only falls back to the Resource when none is given. The music catalog
overrides both, on different types, in the same fluent chain
([`bootstrap.php`](../examples/music-catalog/src/bootstrap.php)):

```php
Server::make()
    // …
    ->register(TrackResource::class, serializer: TrackSerializer::class)
    ->register(PlaylistResource::class, hydrator: PlaylistHydrator::class)
    // …
```

`tracks` overrides the **serializer**: the hand-written
[`TrackSerializer`](../examples/music-catalog/src/Serializer/TrackSerializer.php)
wins for reads (it adds a request-aware `nowPlaying` attribute and a computed
`displayTitle`), while [`TrackResource`](../examples/music-catalog/src/Resource/TrackResource.php)
still hydrates writes. `playlists` overrides the **hydrator**: the hand-written
[`PlaylistHydrator`](../examples/music-catalog/src/Hydrator/PlaylistHydrator.php)
wins for writes (one `title` member fans out to a stored `title` plus a derived
`slug`), while [`PlaylistResource`](../examples/music-catalog/src/Resource/PlaylistResource.php)
still serializes reads.

The split is genuine: write through the hydrator, read through the resource, and
the round-trip resolves the two capabilities from different objects for the same
type ([`HydratorsTest`](../examples/music-catalog/tests/HydratorsTest.php)):

```php
$created = $this->post('/playlists', [/* … title: 'Split Demo' … */]);
$read = $this->get('/playlists/' . $this->id($created));

// PlaylistResource serialized the read; PlaylistHydrator derived the slug on write.
JsonApiDocument::of($read)
    ->assertHasAttribute('title', 'Split Demo')
    ->assertHasAttribute('slug', 'split-demo');
```

See [serializers](serializers.md) and [hydrators](hydrators.md) for when each
override earns its keep, and the constraints on writing one (an override is built
with `new` — no constructor arguments).

## Register a bare pair, skip the Resource

When a type has no field-driven Resource — it is read-only, or write-only, or its
mapping is too custom for the DSL — register the serializer and/or hydrator
directly under an explicit type string:

```php
public function registerSerializerHydrator(
    string $type,
    ?string $serializer = null,
    ?string $hydrator = null,
): self
```

At least one of the two must be supplied; the missing concern has **no Resource
fallback**, so any lookup of it throws. The music catalog registers its read-only
`charts` type this way ([`bootstrap.php`](../examples/music-catalog/src/bootstrap.php)):

```php
Server::make()
    // …
    ->registerSerializerHydrator('charts', serializer: ChartSerializer::class);
```

The key difference from `register()` is the key itself: `register()` is keyed by
**class-string** and reads the type statically off the Resource;
`registerSerializerHydrator()` is keyed by the **type string** you pass, because
there is no Resource to read it from. A bare serializer that needs its URI path
segment to differ from `getType()` implements
[`UriTypeAwareInterface`](resources.md); the
[`ChartSerializer`](../examples/music-catalog/src/Serializer/ChartSerializer.php)
returns `'charts'` from both, so `GET /charts/{id}` routes cleanly.

### The decoupling boundary: `NoResourceRegistered`

A bare pair proves the decoupling because it has no Resource, and the registry
says so. `resourceFor('charts')` throws
[`NoResourceRegistered`](errors-and-exceptions.md) — there is no Resource object
behind the type:

```php
public function resourceFor(string $type): AbstractResource
{
    $entry = $this->entries[$type] ?? throw new NoResourceRegistered($type);
    // … also throws when the entry is a bare pair with no Resource …
}
```

`hasResourceFor('charts')` returns `false` — call it to branch without catching
the exception. The same boundary governs the missing concern: with only a
serializer registered, `hasHydratorFor('charts')` is `false` and `hydratorFor()`
throws `NoResourceRegistered` (there is no Resource to fall back to). That asymmetry
— a serializer present, a hydrator absent — is what makes `charts` read-only.

## Read-only and write-only types

Because the two concerns resolve independently, you supply only what the type
needs:

- A **read-only** type needs only a serializer. `charts` is the worked example:
  no hydrator, so writes have no target. (In a host, the route layer is what
  routes only `GET` for it — see the [Symfony bundle](index.md); core itself just
  refuses to resolve a hydrator.)
- A **write-only** ingest type needs only a hydrator — pass `hydrator:` and omit
  `serializer:`. `serializerFor()` then throws `NoResourceRegistered`, so the type
  has no read representation, which is exactly the point of a write-only ingest.

The read surface for the bare `charts` serializer runs end-to-end — fetch single,
fetch collection, 404 on a miss
([`ChartReadTest`](../examples/music-catalog/tests/ChartReadTest.php)):

```php
$response = $this->get('/charts/1');

self::assertSame(200, $response->getStatusCode());
JsonApiDocument::of($response)
    ->assertHasType('charts')
    ->assertHasAttribute('name', 'Weekly Top')
    ->assertHasAttribute('period', '2024-W03');
```

## The resolver mirror

Reads and writes are symmetric all the way down. Two interfaces describe the
lookup, one per direction, and the [`Server`](server.md) (its schema registry)
implements both:

| Concern | Interface | Resolve | Presence-check |
| --- | --- | --- | --- |
| Read | [`SerializerResolverInterface`](serializers.md) | `serializerFor(type)` | `hasSerializerFor(type)` |
| Write | [`HydratorResolverInterface`](hydrators.md) | `hydratorFor(type)` | `hasHydratorFor(type)` |

Both resolve the override first, then fall back to the Resource:

```php
public function serializerFor(string $type): SerializerInterface
{
    $entry = $this->entries[$type] ?? throw new NoResourceRegistered($type);

    if ($entry->serializer !== null) {            // override wins
        return $this->serializerInstances[$type] ??= $this->makeSerializer($entry->serializer);
    }

    if ($entry->resource === null) {              // bare pair, no serializer → no fallback
        throw new NoResourceRegistered($type);
    }

    return $this->resourceFor($type);             // Resource is the fallback
}
```

`hydratorFor()` is the line-for-line mirror. This symmetry is why a type can carry
a serializer without a hydrator (read-only) or a hydrator without a serializer
(write-only) without any special case: each direction asks its own resolver, and
each resolver answers from the same registry entry. A relationship field reaches
the related type's serializer through `SerializerResolverInterface`, never through
the related Resource directly — which is also how the override is invisible to
callers: they ask the resolver for a type and get whichever object the registry
resolved.

## Next / see also

- [Defining a resource](resources.md) — the `AbstractResource` on-ramp this page
  decomposes.
- [Custom serializers and the polymorphic serializer](serializers.md) — when to
  override or hand-write the read side.
- [Custom hydrators and relationship-write hydration](hydrators.md) — when to
  override or hand-write the write side.
- [The Server: configuring an API](server.md) — `register()` /
  `registerSerializerHydrator()` in the full configurator surface, and lazy
  instantiation of registered capabilities.
