# Custom hydrators

A custom hydrator gives you full control over how a request body fills a domain
object — for the cases a [Resource class](resources.md)'s field declaration can't
express (splitting a member across columns, deriving related models, multi-step or
transactional writes). You implement `Hydrator\HydratorInterface` (directly, or by
extending `Hydrator\AbstractHydrator`) and register it as an override on the type,
replacing the Resource class's hydration without touching its serialization. For the
common case you never write one — a Resource class's `fields()` declaration hydrates
for you — so reach for this only when a write needs logic a field walk can't model.

## When to write one

Drop to a custom hydrator when filling the domain object needs more than writing
each declared field:

- **Splitting one member across columns**, or merging several body members into
  one domain value.
- **Deriving related models** from a write — creating or looking up associated
  objects as part of hydrating the primary one.
- **Multi-step or transactional writes** where the order of operations, or a unit
  of work, matters.

If you only need a one-off custom write for a single field, prefer a field-level
[`deserializeUsing()` / `fillUsing()` hook](fields.md#serialize--hydrate-hooks)
instead of replacing the whole hydrator.

## The contract

`HydratorInterface` is a single method mapping a parsed request and a domain
object to the hydrated object:

```php
interface HydratorInterface
{
    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed;
}
```

The `$domainObject` is the instance to fill — a fresh object on create, the
loaded one on update — and the return value is the (possibly replaced) hydrated
object. The contract is implementable purely by composition: read what you need
off the request (`getResourceType()`, `getResourceId()`, `getResourceAttributes()`,
`getToOneRelationship()` / `getToManyRelationship()`) and return the result.
Throw a [typed exception](exceptions.md) — `ResourceTypeMissing`,
`ResourceTypeUnacceptable`, `ClientGeneratedIdNotSupported`, … — directly; there
is no exception factory.

## Create vs. update dispatch

`AbstractHydrator` is the convenience base. It composes three traits
(`HydratorTrait` + `CreateHydratorTrait` + `UpdateHydratorTrait`) and dispatches
on the HTTP method: `POST` runs the create path, `PATCH` the update path, then a
`validateDomainObject()` hook runs in both. You implement the abstract hooks the
traits declare:

| Hook | Purpose |
|---|---|
| `getAcceptedTypes(): list<string>` | The resource types this hydrator accepts (others raise `ResourceTypeUnacceptable`). |
| `getAttributeHydrator(mixed $obj): array<string, callable>` | Per-attribute fill callables, keyed by attribute name. |
| `getRelationshipHydrator(mixed $obj): array<string, callable>` | Per-relationship fill callables, keyed by name. |
| `setId(mixed $obj, string $id): mixed` | Apply the resolved id to the object. |
| `generateId(): string` | Generate a server-side id on create (UUID v4 preferred). |
| `validateClientGeneratedId(string $id, JsonApiRequestInterface $request): void` | Reject (or accept) a client-supplied id. |
| `validateRequest(JsonApiRequestInterface $request): void` | Request-level validation hook (default no-op). |

Each attribute callable receives `($domainObject, $value, $data, $attributeName)`;
each relationship callable receives `($domainObject, $relationshipObject, $data,
$relationshipName)`. Both may mutate the object in place or return the new one —
a non-null/non-false return replaces the current domain object. An attribute or
relationship absent from a `PATCH` body is skipped, preserving JSON:API update
semantics ("absent means no change").

## Relationship cardinality

The `$relationshipObject` passed to a relationship callable is the request's
parsed linkage value object — a `Hydrator\Relationship\ToOneRelationship` (carries
a nullable `->resourceIdentifier`) or a `ToManyRelationship` (carries a
`->resourceIdentifiers` list, with `getResourceIdentifierIds()` and friends).
`null` / `[]` linkage means "clear the relationship" (`isEmpty()` is true).

Type-hint the callable's **second parameter** to declare the cardinality you
expect; the hydrator reflects that hint and raises `RelationshipTypeInappropriate`
if the incoming linkage is the wrong shape:

```php
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;

'author' => static function (Article $article, ToOneRelationship $author): Article {
    $article->authorId = $author->resourceIdentifier?->id;

    return $article;
},
```

## A worked example

A hydrator that derives a `slug` from the incoming `title` (one body member
feeding an extra column) and links the author relationship:

```php
use haddowg\JsonApi\Hydrator\AbstractHydrator;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

final class ArticleHydrator extends AbstractHydrator
{
    /** @return list<string> */
    protected function getAcceptedTypes(): array
    {
        return ['articles'];
    }

    protected function setId(mixed $domainObject, string $id): mixed
    {
        \assert($domainObject instanceof Article);
        $domainObject->id = $id;

        return $domainObject;
    }

    protected function generateId(): string
    {
        return \bin2hex(\random_bytes(16));
    }

    protected function validateClientGeneratedId(string $clientGeneratedId, JsonApiRequestInterface $request): void
    {
        if ($clientGeneratedId !== '') {
            throw new \haddowg\JsonApi\Exception\ClientGeneratedIdNotSupported();
        }
    }

    protected function validateRequest(JsonApiRequestInterface $request): void {}

    /** @return array<string, callable> */
    protected function getAttributeHydrator(mixed $domainObject): array
    {
        return [
            // One body member writes two columns.
            'title' => static function (Article $article, mixed $title): Article {
                \assert(\is_string($title));
                $article->title = $title;
                $article->slug = \strtolower(\str_replace(' ', '-', $title));

                return $article;
            },
        ];
    }

    /** @return array<string, callable> */
    protected function getRelationshipHydrator(mixed $domainObject): array
    {
        return [
            'author' => static function (Article $article, ToOneRelationship $author): Article {
                $article->authorId = $author->resourceIdentifier?->id;

                return $article;
            },
        ];
    }
}
```

For a write that doesn't split cleanly into per-member callables — a transaction,
a multi-step unit of work — implement `HydratorInterface::hydrate()` directly
instead of extending `AbstractHydrator`, and orchestrate the whole write in that
one method.

## Registering it as an override

Register the hydrator alongside the Resource class with the `hydrator:` argument. The
registry resolves the override ahead of the Resource class for hydration and falls back
to the Resource class for serialization, so you keep the Resource class's field-driven output:

```php
$server = Server::make()
    ->withPsr17($psr17, $psr17)
    ->register(ArticleResource::class, hydrator: ArticleHydrator::class);
```

You can also register a bare hydrator with no Resource class at all (paired with a custom
[serializer](serializers.md)) when a type has no field declaration.

> **Local IDs (`lid`).** JSON:API 1.1 local ids are supported at the data-model
> level: a relationship referencing a not-yet-created resource by `lid` parses and
> reaches the relationship callable with `->resourceIdentifier->lid` set and
> `->id` null; a resource created with a `lid` still gets a server-generated `id`,
> exposed via `$request->getResourceLid()`. Resolving a `lid` to a freshly-created
> resource within one request is not supported.

## Related pages

- [Resources](resources.md) — the field DSL this interface gives you a way around.
- [Resources](serializers.md) — the matching read-side (serializer) customisation point.
- [Server](server.md) — the registry, overrides, and `hydratorFor()`.
- [Exceptions](exceptions.md) — the typed exceptions a hydrator throws.
