# Custom hydrators and relationship-write hydration

A custom hydrator gives you full control over how a request body fills a domain
object — for the writes a [Resource class](resources.md)'s field declaration can't
express. You implement `Hydrator\HydratorInterface` (usually by extending one of
the three operation-scoped bases) and register it as a write override on the type,
replacing the Resource's hydration without touching its serialization. For the
common case you never write one: a Resource's [`fields()`](fields.md) declaration
hydrates for you, so reach for this only when a field walk can't model the write.

## The escape-hatch tiers

Customising a write is graduated — drop only as far as you need:

1. **One field.** A single member that deserialises or fills awkwardly is best
   handled by a field-level [`deserializeUsing()` / `fillUsing()` hook](fields.md),
   leaving the rest of the Resource's field walk intact.
2. **A whole type (last resort).** When the write needs cross-member logic — one
   member fanning out to several columns, deriving related models, a transactional
   unit of work — replace the type's hydrator entirely. That is this page.

## When to write one

Reach for a full hydrator when filling the domain object needs more than writing
each declared field independently:

- **Splitting one member across columns**, or merging several body members into
  one domain value.
- **Deriving related models** during a write — creating or looking up associated
  objects as part of hydrating the primary one.
- **Multi-step or transactional writes** where the order of operations, or a unit
  of work, matters.

The music catalog's [`playlists`](../examples/music-catalog/src/Resource/PlaylistResource.php)
type is the worked case: one client member, `title`, fans out to **two** stored
columns — the title itself and a derived, read-only `slug` the field DSL never
lets the client set.

## The contract

`HydratorInterface` is a single method mapping a parsed request and a domain
object to the hydrated object:

```php
interface HydratorInterface
{
    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed;
}
```

The `$domainObject` is the instance to fill — a **fresh** object on create, the
**fetched** one on update — and the return value is the (possibly replaced)
hydrated object. The contract is implementable purely by composition: read what
you need off the request (`getResource()`, `getResourceType()`, `getResourceId()`,
`getToOneRelationship()` / `getToManyRelationship()`) and return the result. Throw
a [typed exception](errors-and-exceptions.md) — `ResourceTypeMissing`,
`ResourceTypeUnacceptable`, `ClientGeneratedIdNotSupported`, … — directly; there
is no exception factory.

For a transactional write — a unit of work where ordering matters and per-member
callables don't fit — implement `hydrate()` directly and orchestrate the whole
write in that one method. For everything else, extend a base.

## The three operation-scoped bases

Three abstract bases implement `hydrate()` for you and dispatch on the HTTP
method. Pick the one matching the operations your type accepts:

| Base | Operations | Implements |
|---|---|---|
| `Hydrator\AbstractHydrator` | create (`POST`) + update (`PATCH`) + relationship endpoints | `HydratorInterface`, `UpdateRelationshipHydratorInterface` |
| `Hydrator\AbstractCreateHydrator` | create (`POST`) only | `HydratorInterface` |
| `Hydrator\AbstractUpdateHydrator` | update (`PATCH`) + relationship endpoints | `HydratorInterface`, `UpdateRelationshipHydratorInterface` |

`AbstractHydrator` composes three traits (`HydratorTrait` + `CreateHydratorTrait` +
`UpdateHydratorTrait`): `POST` runs the create path, `PATCH` the update path, and a
`validateDomainObject()` hook runs after both. The create-only and update-only
bases drop the trait they don't need — useful for a write-once log (create only)
or an immutable-key resource that only ever patches.

## The hooks

Extending a base, you fill in the abstract hooks the traits declare. Not every
hook exists on every base (create-only has no relationship hooks, for instance),
but the full set is:

| Hook | Purpose |
|---|---|
| `getAcceptedTypes(): list<string>` | The resource types this hydrator accepts; any other raises `ResourceTypeUnacceptable`. |
| `getAttributeHydrator(mixed $obj): array<string, callable>` | Per-attribute fill callables, keyed by attribute name. |
| `getRelationshipHydrator(mixed $obj): array<string, callable>` | Per-relationship fill callables, keyed by name. |
| `setId(mixed $obj, string $id): mixed` | Apply the resolved id to the object. |
| `generateId(): string` | Generate a server-side id on create when the client supplies none (UUID v4 preferred). Abstract — you implement it; there is no silent auto-UUID. For a store-provided id (the DB assigns it), leave `setId()` a no-op instead. |
| `validateClientGeneratedId(string $id, JsonApiRequestInterface $request): void` | Reject (or accept) a client-supplied id; throw `ClientGeneratedIdNotSupported` to refuse. |
| `validateRequest(JsonApiRequestInterface $request): void` | Request-level validation, called after type and id checks (**abstract — you must implement it**, even as an empty no-op, as `PlaylistHydrator` does). |
| `validateDomainObject(JsonApiRequestInterface $request, mixed $obj): void` | **Post-hydration** seam, called once the object is fully built (default no-op). |

`validateDomainObject()` is the seam where adapter-level, cross-field, or
entity-level checks hang — the rules a per-member callable can't see because they
span the whole object. It runs after the create and update paths alike.

This hand-written family sources the id through the `generateId()` /
`validateClientGeneratedId()` / `setId()` hooks, **not** the declarative `Id`-field
SOURCE/POLICY model `AbstractResource` reads (`allowClientId()` / `requireClientId()`
/ `generated()` / store-provided).
The two create paths are deliberately separate — a hydrator built on this family
expresses the same choices through its hooks (mint a format in `generateId()`, throw
from `validateClientGeneratedId()` to require a client id, leave `setId()` a no-op for
a store-provided id). This decision is pinned by `CreateHydratorTraitTest`.

## Attribute and relationship callables

Each **attribute** callable receives `($domainObject, $value, $data, $attributeName)`;
each **relationship** callable receives `($domainObject, $relationshipObject, $data,
$relationshipName)`. Both may mutate the object in place **or** return the new one —
a truthy return replaces the current domain object, a `null`/`false` return keeps
the one passed in. An attribute or relationship absent from a `PATCH` body is
skipped, preserving JSON:API update semantics: *absent means no change*.

### Relationship cardinality by type-hint

The `$relationshipObject` is the request's parsed linkage value object — a
`Hydrator\Relationship\ToOneRelationship` (carrying a nullable `->resourceIdentifier`)
or a `Hydrator\Relationship\ToManyRelationship` (carrying a `->resourceIdentifiers`
list). `isEmpty()` is true when the request wants to clear the relationship
(`"data": null` for to-one, `"data": []` for to-many).

| Value object | Carries | Accessors |
|---|---|---|
| `ToOneRelationship` | `->resourceIdentifier` (nullable) | `isEmpty()` |
| `ToManyRelationship` | `->resourceIdentifiers` (list) | `getResourceIdentifierIds()`, `getResourceIdentifierTypes()`, `getResourceIdentifierLids()`, `isEmpty()` |

Each [`ResourceIdentifier`](concepts.md#resource-identifiers) exposes `->type`,
`->id`, `->lid`, and `->meta` (the `->lid` carries a JSON:API 1.1 local id — see
[the `lid` callout under registering a hydrator](#registering-a-hydrator) below).

**Type-hint the callable's second parameter to declare the cardinality you
expect.** The hydrator reflects that hint and raises
`RelationshipTypeInappropriate` if the incoming linkage is the wrong shape (a
to-many body sent to a `ToOneRelationship`-hinted callable, say). Leave the hint
off (`mixed`) to accept either.

## A worked hydrator

[`PlaylistHydrator`](../examples/music-catalog/src/Hydrator/PlaylistHydrator.php)
extends `AbstractHydrator`. Its headline job is the fan-out: one `title` member
fills the title and derives the read-only `slug` — a value the client can never
set directly.

```php
use haddowg\JsonApi\Examples\MusicCatalog\Domain\Playlist;
use haddowg\JsonApi\Hydrator\AbstractHydrator;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

final class PlaylistHydrator extends AbstractHydrator
{
    protected function getAcceptedTypes(): array
    {
        return ['playlists'];
    }

    protected function getAttributeHydrator(mixed $domainObject): array
    {
        return [
            // The fan-out: one `title` member fills the title AND derives the
            // read-only `slug`. A field-DSL resource cannot express "set one
            // column from another" — this is why you hand-write a hydrator.
            'title' => static function (mixed $playlist, mixed $value, array $data, string $field): Playlist {
                \assert($playlist instanceof Playlist);
                $title = \is_string($value) ? \trim($value) : '';
                $playlist->title = $title;
                $playlist->slug = self::slugify($title);

                return $playlist;
            },
            // …
        ];
    }
    // …
}
```

The id hooks below let the type accept a client-supplied UUID — the
[`PlaylistResource`](../examples/music-catalog/src/Resource/PlaylistResource.php)
opts in with `Id::make()->uuid()->allowClientId()`, so `validateClientGeneratedId()`
is a no-op rather than a throw, and `generateId()` mints a UUID when the client
omits one:

```php
protected function validateClientGeneratedId(string $clientGeneratedId, JsonApiRequestInterface $request): void
{
    // Accepted: no-op. A type that did not opt in would throw here.
}

protected function setId(mixed $domainObject, string $id): mixed
{
    \assert($domainObject instanceof Playlist);
    $domainObject->id = $id;

    return $domainObject;
}
```

Finally the post-hydration seam — a cross-field rule the field DSL cannot express,
checked once the object is fully built:

```php
protected function validateDomainObject(JsonApiRequestInterface $request, mixed $domainObject): void
{
    \assert($domainObject instanceof Playlist);

    if ($domainObject->title !== '' && $domainObject->slug === '') {
        throw new \LogicException('A titled playlist must have a derived slug.');
    }
}
```

A `POST /playlists` with `{"title": "Chill Out Sessions"}` comes back `201` with
both `title` and the derived `slug` (`chill-out-sessions`) — and a client-sent
`slug` is ignored, because it's read-only and the hydrator owns the derivation.
A title that slugifies to nothing (`"!!!"`) is rejected by the seam. The full
witness is [`HydratorsTest`](../examples/music-catalog/tests/HydratorsTest.php).

> **The relationship-hydrator split.** `PlaylistHydrator::getRelationshipHydrator()`
> returns `[]` and lets the example's [handler](../examples/music-catalog/src/Handler/MusicCatalogHandler.php)
> apply relationships. That's a domain choice, not a rule: this store holds the
> *related objects* (a `Playlist` carries `User`/`Track` instances), so a linkage
> id must be resolved to the stored object before it's set — work that needs the
> store the hydrator has no handle on (the store/persister is the host
> integration's data layer — see [the Symfony bundle](index.md#scope-boundaries);
> core itself only parses the linkage). When your write only needs the linkage
> **id** (a foreign-key column), put the callable here and type-hint its
> cardinality as shown above.

## Writing relationship endpoints

The standalone relationship endpoints — `PATCH`, `POST`, `DELETE`
`/{type}/{id}/relationships/{rel}` — hydrate through a separate seam,
`UpdateRelationshipHydratorInterface`:

```php
interface UpdateRelationshipHydratorInterface
{
    public function hydrateRelationship(
        string $relationship,
        JsonApiRequestInterface $request,
        mixed $domainObject,
    ): mixed;
}
```

`AbstractHydrator` and `AbstractUpdateHydrator` implement it for you, routing the
named relationship through the same `getRelationshipHydrator()` map. The HTTP verb
selects a `Resource\Field\Mode` — `PATCH` → `Mode::Replace`, `POST` →
`Mode::Add`, `DELETE` → `Mode::Remove`.

When the type's writes go through a [`Resource`](resources.md) (no hydrator
override), `AbstractResource` implements `UpdateRelationshipHydratorInterface`
directly, and the mutability flags on each relation field are enforced there: a
replace against a relation that disallows it throws `FullReplacementProhibited`
(`403`), a remove against an immutable relation throws `RemovalProhibited`
(`403`), and an `Add`/`Remove` against a to-one relation is the inappropriate
shape. See [relationship mutation](relationship-mutation.md) for the full picture,
including the matching `DataPersister` apply step (the persister is a host-layer
seam — core parses and validates the linkage, the host integration writes it).

## Registering a hydrator

Register the hydrator alongside the Resource with the `hydrator:` argument. The
registry resolves the override ahead of the Resource for **writes**, and falls
back to the Resource for **reads** — so the field-driven serialization survives
untouched:

```php
$server = Server::make()
    ->withPsr17($psr17, $psr17)
    // …
    ->register(PlaylistResource::class, hydrator: PlaylistHydrator::class);
```

This is the read/write split: the same `playlists` type serialises through
`PlaylistResource` and hydrates through `PlaylistHydrator`, the two capabilities
resolved from different objects. You can also register a **bare** hydrator with no
Resource at all — paired with a custom [serializer](serializers.md) under an
explicit type via `registerSerializerHydrator()` — for a type that has no field
declaration. That, and the resolution order between an override and the Resource
fallback, are covered in [capability composition](capability-composition.md).

Internally, an operation handler resolves the hydrator via
`Hydrator\HydratorResolverInterface` (`hydratorFor($type)` / `hasHydratorFor($type)`),
the write-side mirror of the serializer resolver — backed by the
[`Server`](server.md) registry, so a handler never depends on the concrete
`Server`.

> **Local ids (`lid`).** JSON:API 1.1 local ids are supported at the data-model
> level: a relationship referencing a not-yet-created resource by `lid` parses and
> reaches the callable with `->resourceIdentifier->lid` set and `->id` null; a
> resource created with a `lid` still gets a server-generated `id`, exposed via
> `$request->getResourceLid()`. Resolving a `lid` to a freshly-created resource
> *within one request* is not supported.

## Next / see also

- [Fields](fields.md) — the field DSL and its per-field `deserializeUsing()` /
  `fillUsing()` hooks, the lighter escape hatch.
- [Serializers](serializers.md) — the read-side twin of this customisation point.
- [Capability composition](capability-composition.md) — override resolution, bare
  registration, and read-only / write-only types.
- [Relationship mutation](relationship-mutation.md) — the relationship-endpoint
  write flow, `Mode`, and the persister apply step.
- [Exceptions](errors-and-exceptions.md) — the typed exceptions a hydrator throws.
