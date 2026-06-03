# Serializers

A custom serializer gives you full control over how a domain object becomes a
JSON:API resource — for the cases a [Resource class](resources.md)'s field
declaration can't express (request-aware or computed attributes, multiple
representations of one model). You implement `Serializer\SerializerInterface`
directly (or extend `Serializer\AbstractSerializer`) and register it as an override
on the type, replacing the Resource class's serialization without touching its
hydration. For the common case you never write one — a Resource class's `fields()`
declaration serializes for you — so reach for this only when serialization needs
logic a field walk can't model.

> **A note on names.** "Resource" is overloaded. The class documented here is a
> *serializer* — `Serializer\SerializerInterface`, the lower-level serializer
> contract. It is **not** the JSON:API spec's *resource object* (the
> `{type, id, attributes, relationships}` structure inside `data`), which this
> package emits as a plain array from the serialization engine rather than as a
> class you write (there is no `ResourceObject` class). It is also not the
> [Resource class](resources.md) (`Resource\AbstractResource`), the primary surface
> a custom serializer gives you a way around. See [Concepts](concepts.md#vocabulary).

## When to write one

Drop to a custom serializer when serialization needs more than reading each
declared field off the model:

- **Request-aware or conditional attributes** — a member that appears, changes
  shape, or is computed differently depending on the current request (the
  serializer receives the `JsonApiRequestInterface` for every attribute).
- **Computed or derived values** that draw on several model members at once, or
  on data outside the model.
- **Multiple representations of one model** — the same domain object exposed as
  more than one resource type, registered under different serializers.

If you only need a one-off custom value for a single field, prefer a field-level
[`serializeUsing()` / `extractUsing()` hook](fields.md#serialize--hydrate-hooks)
instead of replacing the whole serializer.

## The contract

`SerializerInterface` maps a domain value (`mixed` — an object, an array, or any
representation) to the parts of a JSON:API resource object:

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

The serializer is **stateless**: every method is a pure function of its
arguments, so a single instance safely serializes many objects — collection
items and recursively included resources alike. A resource's identity
(`getType()` / `getId()`) and its default includes depend only on the object; the
request-shaped members (`getMeta()` / `getLinks()` / `getAttributes()` /
`getRelationships()`) receive the request directly.

`getAttributes()` and `getRelationships()` return **maps of callables**, not
values: each callable receives the domain object, the request, and the member
name, and returns the value (or, for a relationship, an `AbstractRelationship`).
The engine invokes only the callables for members that survive sparse-fieldset
filtering. The request is passed to `getAttributes()` / `getRelationships()`
themselves as well, so the *set* of members — not just each value — can depend on
the request.

## A worked example

`AbstractSerializer` adds the `Serializer\TransformerTrait` date/decimal formatting
helpers and nothing else — the serializer is stateless, so there is nothing
per-pass to manage. The trait is public and composable: if you implement
`SerializerInterface` directly you can `use TransformerTrait` on your own class
rather than extend the base. Identity (`getType()` / `getId()`) depends only on the
object, while the request-shaped members receive the request directly. This
`ArticleSerializer`
exposes a request-aware `body` (omitted unless the caller is the author) and a
computed `wordCount`; the request arrives as a method parameter:

```php
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Serializer\AbstractSerializer;

final class ArticleSerializer extends AbstractSerializer
{
    public function getType(mixed $object): string
    {
        return 'articles';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Article);

        return $object->id;
    }

    /** @return array<string, mixed> */
    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        \assert($object instanceof Article);

        return ResourceLinks::withoutBaseUri(new Link('/articles/' . $object->id));
    }

    /** @return array<string, callable(mixed, JsonApiRequestInterface, string): mixed> */
    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        $attributes = [
            'title' => static fn (Article $a): string => $a->title,
            'wordCount' => static fn (Article $a): int => \str_word_count($a->body),
        ];

        // Request-aware: only the author sees the full body. The active request
        // arrives as a parameter — the serializer keeps no per-pass state.
        $viewer = $request->getHeaderLine('X-User-Id');
        if ($object instanceof Article && $viewer === $object->authorId) {
            $attributes['body'] = static fn (Article $a): string => $a->body;
        }

        return $attributes;
    }

    /** @return list<string> */
    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    /** @return array<string, callable(mixed, JsonApiRequestInterface, string): AbstractRelationship> */
    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }
}
```

> **Override serializers take no constructor arguments.** The registry
> instantiates an override with `new ArticleSerializer()` and — unlike the Resource
> class — does **not** inject the relationship `SerializerResolverInterface`. A custom serializer is
> therefore best suited to shaping `attributes` (request-aware, conditional,
> computed). When a type needs both related-resource serialization *and* attribute
> logic the field walk can't express, keep the [Resource class](resources.md) and override
> only the narrower concern, or relate types through the Resource class rather than a
> hand-written serializer.

## Registering it as an override

Register the serializer alongside the Resource class with the `serializer:` argument. The
registry resolves the override ahead of the Resource class for serialization and falls
back to the Resource class for hydration, so you keep the Resource class's field-driven writes:

```php
$server = Server::make()
    ->withPsr17($psr17, $psr17)
    ->register(ArticleResource::class, serializer: ArticleSerializer::class);
```

You can also register a bare serializer with no Resource class at all (paired with a
custom [hydrator](hydrators.md)) when a type has no field declaration, under an
explicit `$type` with
[`registerSerializerHydrator()`](server.md#bare-serializer--hydrator-pairs).

> The field declaration and this interface are the supported ways to define
> serialization.

## Related pages

- [Resources](resources.md) — the field DSL this interface gives you a way around.
- [Hydrators](hydrators.md) — the matching write-side customisation point.
- [Server](server.md) — the registry, overrides, and `serializerFor()`.
- [Concepts](concepts.md) — the document model and the serializer/resource-object vocabulary.
