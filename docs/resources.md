# Custom serializers

A custom serializer is the escape hatch for when the [schema](schemas.md) field
DSL can't express how a domain object becomes a JSON:API resource. You implement
`Serializer\SerializerInterface` directly (or extend `Serializer\AbstractSerializer`)
and register it as an override on the type, replacing the schema's serialization
without touching its hydration. For the common case you never write one — a
schema's `fields()` declaration serializes for you — so reach for this only when
serialization needs logic a field walk can't model.

> **A note on names.** "Resource" is overloaded. The class documented here is a
> *serializer* — `Serializer\SerializerInterface`, what woohoolabs/yin called a
> `Resource`. It is **not** the JSON:API spec's *resource object* (the
> `{type, id, attributes, relationships}` structure inside `data`), which is
> `Schema\ResourceObject` internally. See [Concepts](concepts.md#vocabulary).

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
[`serializeUsing()` / `extractUsing()` hook](fields.md#custom-serialize--hydrate-hooks)
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
    public function getMeta(mixed $object): array;

    public function getLinks(mixed $object): ?ResourceLinks;

    /** @return array<string, callable(mixed, JsonApiRequestInterface, string): mixed> */
    public function getAttributes(mixed $object): array;

    /** @return list<string> */
    public function getDefaultIncludedRelationships(mixed $object): array;

    /** @return array<string, callable(mixed, JsonApiRequestInterface, string): AbstractRelationship> */
    public function getRelationships(mixed $object): array;

    /** @internal */
    public function initializeTransformation(JsonApiRequestInterface $request, mixed $object): void;
    /** @internal */
    public function clearTransformation(): void;
}
```

`getAttributes()` and `getRelationships()` return **maps of callables**, not
values: each callable receives the domain object, the active request, and the
member name, and returns the value (or, for a relationship, an
`AbstractRelationship`). Returning callables is what lets a member be
request-aware and lets the engine call only the members it actually needs.

The two `initializeTransformation()` / `clearTransformation()` methods are
`@internal` — the serialization engine calls them around a pass to hand you the
request and object; you do not call them. Extending `AbstractSerializer` (below)
implements them for you.

## A worked example

`AbstractSerializer` stores the active request and object for the pass (reachable
as `$this->request` / `$this->object`) and implements the two `@internal`
lifecycle methods, so you implement only the mapping methods. This `ArticleSerializer`
exposes a request-aware `body` (omitted unless the caller is the author) and a
computed `wordCount`:

```php
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\SerializerResolver;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationship;
use haddowg\JsonApi\Serializer\AbstractSerializer;

final class ArticleSerializer extends AbstractSerializer
{
    public function __construct(private readonly SerializerResolver $resolver) {}

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
    public function getMeta(mixed $object): array
    {
        return [];
    }

    public function getLinks(mixed $object): ?ResourceLinks
    {
        \assert($object instanceof Article);

        return ResourceLinks::withoutBaseUri(new Link('/articles/' . $object->id));
    }

    /** @return array<string, callable(mixed, JsonApiRequestInterface, string): mixed> */
    public function getAttributes(mixed $object): array
    {
        $attributes = [
            'title' => static fn (Article $a): string => $a->title,
            'wordCount' => static fn (Article $a): int => \str_word_count($a->body),
        ];

        // Request-aware: only the author sees the full body.
        if ($this->viewerIsAuthor($object)) {
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
    public function getRelationships(mixed $object): array
    {
        return [
            'author' => fn (Article $a): AbstractRelationship => ToOneRelationship::create()
                ->setData($a->author, $this->resolver->serializerFor('authors')),
        ];
    }

    private function viewerIsAuthor(mixed $object): bool
    {
        return $object instanceof Article
            && $this->request?->getHeaderLine('X-User-Id') === $object->author->id;
    }
}
```

Relationship callables build a `Schema\Relationship\ToOneRelationship` /
`ToManyRelationship` via `create()` and attach the related object with
`setData($related, $serializer)`, resolving the related type's serializer through
the injected resolver — the [server's registry](server.md) is the resolver, so
inject it where you need to relate other types.

## Registering it as an override

Register the serializer alongside the schema with the `serializer:` argument. The
registry resolves the override ahead of the schema for serialization and falls
back to the schema for hydration, so you keep the schema's field-driven writes:

```php
$server = Server::make()
    ->withPsr17($psr17, $psr17)
    ->register(ArticleResource::class, serializer: ArticleSerializer::class);
```

You can also register a bare serializer with no schema at all (paired with a
custom [hydrator](hydrators.md)) when a type has no field declaration — exactly
the wiring the library shipped with before the fluent schema existed.

> Attribute-driven serializers (deriving the field map from PHP attributes on the
> model) are a candidate for a post-1.0 release; in 1.0 the field DSL and this
> interface are the two supported paths.

## Related pages

- [Schemas](schemas.md) — the field DSL this interface is the escape hatch from.
- [Hydrators](hydrators.md) — the matching write-side escape hatch.
- [Server](server.md) — the registry, overrides, and `serializerFor()`.
- [Concepts](concepts.md) — the document model and the serializer/resource-object vocabulary.
