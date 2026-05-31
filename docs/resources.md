# Resources

A Resource class is the recommended way to describe a JSON:API resource type. You
subclass `Resource\AbstractResource`, set its `$type`, and implement `fields()`;
that one declaration satisfies **both** the serializer contract (turning a domain
object into a resource object) and the hydrator contract (filling a domain object
from a request body). For the 95% case you never write a serializer or hydrator by
hand.

> **A note on names.** "Resource" is overloaded. The JSON:API spec's *resource
> object* — the `{type, id, attributes, relationships}` structure inside `data` —
> is emitted by the serialization engine as a plain array, not a class you write
> (there is no `ResourceObject` class). The class you subclass here,
> `Resource\AbstractResource`, is the *Resource class*: it maps domain objects ↔
> JSON:API resources, serving as a per-type serializer + hydrator. The lower-level
> `Serializer\SerializerInterface` / `Hydrator\HydratorInterface` contracts a
> Resource class satisfies are also usable directly when you need full control. See
> [Concepts](concepts.md#vocabulary).

## A minimal Resource class

```php
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class ArticleResource extends AbstractResource
{
    public static string $type = 'articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(255)->sortable(),
            Str::make('body')->required(),
        ];
    }
}
```

`$type` is the JSON:API type member and the key the Resource class registers
under. Every entry in `fields()` is a [`Field`](fields.md): an `Id`, an attribute,
or a relationship. The order is preserved in output.

## What a Resource class declares

`AbstractResource` exposes a small set of overridable methods. Only `fields()` is
required.

| Method | Returns | Purpose |
|---|---|---|
| `fields()` | `list<Field>` | The attribute + relationship inventory (required). |
| `filters()` | `list<Filter>` | The [filters](filters.md) this type accepts (default: none). |
| `sorts()` | `list<Sort>` | Computed/multi-column [sorts](sorts.md) beyond the field-derived ones. |
| `pagination()` | `?Paginator` | The default [pagination](pagination.md) strategy for collections (default: the server's). |

`allSorts()` is derived for you: every field marked `->sortable()` yields a
`SortByField`, merged with anything `sorts()` adds — so you rarely override
`sorts()`.

## How fields drive serialization

When the engine serializes a model, it walks the non-hidden fields:

- The `Id` field produces the resource object's top-level `id`.
- Attribute fields produce `attributes`, each read from the model via a
  framework-agnostic accessor (a public property, a `getXxx()` getter, or an array
  key) — or via the field's own `serializeUsing()` / `extractUsing()` hook.
- Relationship fields produce `relationships`, serializing the related type
  through the [server's registry](server.md).

Sparse fieldsets (`?fields[articles]=title`) and inclusion (`?include=author`) are
applied by the engine reading the request — the Resource class emits every
eligible field and lets the engine narrow. Mark a field `->hidden()` to drop it from output
entirely, or `->notSparseField()` to exempt it from sparse-fieldset filtering.

## How fields drive hydration

For a `POST` (create) or `PATCH` (update), the same fields fill the domain object:

- `Id` resolves the resource id. By default a client-supplied `id` is rejected
  (`ClientGeneratedIdNotSupported`); override `acceptsClientGeneratedId()` to
  allow it, and `generateId()` to control server-side id generation (the default
  is a v4 UUID).
- Attribute fields write to the model via the accessor (or the field's
  `deserializeUsing()` / `fillUsing()` hook), unless the field is read-only in
  that context (`->readOnly()`, `->readOnlyOnCreate()`, `->readOnlyOnUpdate()`).
- Relationship fields are filled from the request's parsed linkage, not from a raw
  attribute value.

Hydration respects JSON:API update semantics: an attribute absent from a `PATCH`
body is left unchanged.

## Registering a Resource class

A Resource class becomes active when registered on a [`Server`](server.md):

```php
use haddowg\JsonApi\Server\Server;

$server = Server::make()
    ->withPsr17($psr17, $psr17)
    ->register(ArticleResource::class)
    ->register(AuthorResource::class);
```

`register()` takes class-strings and instantiates lazily; the Resource class's
static `$type` keys the registry. Registering two Resource classes for the same
type is a wiring error (a `\LogicException`). The registry is also the resolver relationships use to
serialize related types, so registering all participating types is what lets
`include` and relationship linkage work.

## Relationships

A relationship is a field too. Declare the related type with `->type()`; the
related resource serializes through the registry:

```php
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;

public function fields(): array
{
    return [
        Id::make(),
        Str::make('title')->required(),
        BelongsTo::make('author')->type('authors')->required(),
        HasMany::make('comments')->type('comments'),
    ];
}
```

See [Fields](fields.md#relationships) for every relationship type
(`BelongsTo`/`HasOne`/`HasMany`/`BelongsToMany`/`MorphTo`) and their options.

## When you need more control

The field DSL covers the common cases. When serialization needs request-aware or
computed attributes, multiple representations of one model, or other logic the
field walk can't express, a custom [serializer](serializers.md) gives you full
control of the read side. When a write needs to split a member across columns,
derive related models, or run a multi-step/transactional write, a custom
[hydrator](hydrators.md) gives you full control of the write side. Register either
as an override alongside the Resource class:

```php
$server->register(ArticleResource::class, serializer: ArticleSerializer::class);
$server->register(ArticleResource::class, hydrator: ArticleHydrator::class);
```

The registry resolves an override ahead of the Resource class and falls back to
the Resource class for the concern you didn't override. You can also register a
bare serializer + hydrator pair with no Resource class at all.

## Validation

Field [constraints](validation.md) (`->required()`, `->maxLength()`, …) are
**metadata**. The core never executes them against data; they are consumed by the
optional [JSON Schema compiler](validation.md#per-resource-schemas) for structural
request validation, and are available to framework adapters for full validation.
See [Validation](validation.md) for the constraint vocabulary and the create/update
context model.

## Related pages

- [Fields](fields.md) — every field type and fluent option.
- [Validation](validation.md) — constraints, contexts, the JSON Schema compiler.
- [Filters](filters.md) / [Sorts](sorts.md) — query-shaping metadata.
- [Pagination](pagination.md) — per-resource and server-default paginators.
- [Serializers](serializers.md) / [Hydrators](hydrators.md) — the per-type customisation points.
- [Server](server.md) — registration and the registry.
