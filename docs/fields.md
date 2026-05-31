# Fields

A field is one entry in a [Resource class](resources.md)'s `fields()` list. Each field
describes one member of a resource type — the `id`, an attribute, or a
relationship — and that single declaration drives both directions: how the
member is serialized out of a domain object and how it is hydrated back into one.
Fields are **mutable builders**: every fluent method mutates the field and
returns it, so a field reads as one expression
(`Str::make('title')->required()->maxLength(200)->sortable()`). This page
documents the shared fluent surface, then every concrete field type and its
type-specific options.

```php
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

public function fields(): array
{
    return [
        Id::make(),
        Str::make('title')->required()->maxLength(200),
    ];
}
```

## The shared fluent surface

Every field extends `Resource\Field\AbstractField` and inherits the methods
below. The constructor takes the JSON:API member name and, optionally, the
backing domain-object member it maps to; in practice you call the static
`make()` factory rather than `new`.

```php
Str::make('title');           // name and column both 'title'
Str::make('title', 'heading') // name 'title', backed by the 'heading' member
```

### Naming and storage

| Method | Effect |
|---|---|
| `make(string $name)` | Constructs the field. The member name is also the default backing column. |
| `storedAs(string $column)` | Reads/writes a different domain-object member than the JSON:API member name. |
| `computed()` | Marks the field as having no backing column. Pair with `extractUsing()` for the value. |

The backing member is resolved through a framework-agnostic accessor: a public
property, a `getXxx()` / `setXxx()`-style accessor, or an array key, in that
order. You never wire it up explicitly.

### Visibility and query eligibility

| Method | Effect |
|---|---|
| `hidden()` | Drops the field from output entirely. |
| `notSparseField()` | Exempts the field from sparse-fieldset (`?fields[type]=…`) filtering, so it is always emitted. |
| `sortable()` | Marks the field as sortable; the Resource class's `allSorts()` derives a [`SortByField`](sorts.md) for it. |

### Read-only contexts

A read-only field is still serialized but is ignored during hydration in the
matching context.

| Method | Effect |
|---|---|
| `readOnly()` | Read-only on both create and update. |
| `readOnlyOnCreate()` | Read-only on create (POST) only. |
| `readOnlyOnUpdate()` | Read-only on update (PATCH) only. |

### Serialize / hydrate hooks

When the accessor and per-type casting are not enough, override a single
member's behaviour with a closure. These customise one field; for a whole
resource type, drop to a custom [serializer](serializers.md) or
[hydrator](hydrators.md) instead.

| Method | Closure signature | Replaces |
|---|---|---|
| `serializeUsing($fn)` | `fn(mixed $model, JsonApiRequestInterface $request, string $name): mixed` | The full read path (accessor + cast). |
| `extractUsing($fn)` | `fn(mixed $model, JsonApiRequestInterface $request, string $name): mixed` | The raw read; per-type casting still applies. |
| `deserializeUsing($fn)` | `fn(mixed $value, array $data): mixed` | The per-type cast on hydration. |
| `fillUsing($fn)` | `fn(mixed $model, mixed $value, array $data, string $name): mixed` | The full write path; return the model (or `null` to keep it unchanged). |

### Constraint shortcuts

These appear on every field; type-specific constraints (lengths, bounds, …) live
on the concrete types below. All constraints are **metadata** — the core never
runs them against data; they feed the optional [JSON Schema compiler](validation.md)
and framework adapters. See [Validation](validation.md) for the full vocabulary
and the create/update context model.

| Method | Adds |
|---|---|
| `required()` | `Required` (always). |
| `requiredOnCreate()` | `Required` on create (POST) only. |
| `requiredOnUpdate()` | `Required` when supplied on update (PATCH) only. |
| `nullable()` | `Nullable` — the member may be `null`. |
| `in(array $values)` | `In` — value must be one of `$values`. |
| `notIn(array $values)` | `NotIn` — value must not be one of `$values`. |

### Scoping constraints to a context

`onCreate()` / `onUpdate()` scope **every** constraint appended inside the
closure to that context, so you don't repeat `…OnCreate` per call:

```php
Str::make('slug')->onCreate(function (Str $field): void {
    $field->required()->maxLength(64);
});
```

## Attribute field types

Each concrete type adds per-type casting (its `serializeValue()` /
`deserializeValue()`) and a small set of type-specific constraint helpers. All of
them inherit the shared surface above.

### `Id`

`Id::make()` declares the resource's top-level `id` member. The name defaults to
`'id'` (pass another if your domain object stores it elsewhere — e.g.
`Id::make('uuid')`). Unlike attribute fields it is rendered into the resource's
`id`, not into `attributes`, and is hydrated via the hydrator's id hook.

| Method | Adds |
|---|---|
| `uuid(?int $version = null)` | A UUID client-generated-id format constraint. |
| `numeric()` | A `^[0-9]+$` pattern constraint. |
| `pattern(string $regex)` | An arbitrary pattern constraint. |

```php
Id::make();            // reads the 'id' member, serialized as a string
Id::make()->uuid(4);   // constrains a client-generated id to UUID v4
```

> A client-supplied `id` is rejected by default. See
> [Resources](resources.md#how-fields-drive-hydration) for opting in and controlling
> server-side id generation.

### `Str`

A generic string attribute, and the base for the dedicated string types below.

| Method | Adds |
|---|---|
| `minLength(int)` / `maxLength(int)` | `MinLength` / `MaxLength`. |
| `pattern(string $regex)` | `Pattern`. |
| `email()` | `EmailFormat`. |
| `url(array $allowedSchemes = [])` | `UrlFormat`. |
| `uuid(?int $version = null)` | `UuidFormat`. |
| `slug(?string $regex = null)` | `SlugFormat`. |
| `ip(?int $version = null)` | `IpFormat`. |

```php
Str::make('title')->required()->minLength(1)->maxLength(200);
```

The `email()` / `url()` / `uuid()` / `slug()` / `ip()` shortcuts produce exactly
the same constraint metadata as the dedicated field types below — `Str::make('contact')->email()`
and `Email::make('contact')` are interchangeable. Reach for the dedicated type
when the field is *only* that format; reach for the shortcut when you also want
other string constraints in the same chain.

### `Email`, `Url`, `Uuid`, `Slug`, `Ip`

Each is a `Str` whose `make()` pre-applies the matching format constraint, plus a
type-specific helper:

| Type | Equivalent to | Extra helper |
|---|---|---|
| `Email::make($name)` | `Str::make($name)->email()` | `strict()` — opt into RFC-strict validation (adapter metadata; JSON Schema `format: email` is unaffected). |
| `Url::make($name)` | `Str::make($name)->url()` | `allowedSchemes(string ...$schemes)` — restrict the URI schemes. |
| `Uuid::make($name)` | `Str::make($name)->uuid()` | `version(int)` — narrow to a UUID version. |
| `Slug::make($name)` | `Str::make($name)->slug()` | — |
| `Ip::make($name)` | `Str::make($name)->ip()` | `v4()` / `v6()` / `both()` — narrow the IP version (default both). |

```php
Email::make('contact')->required();
Url::make('homepage')->allowedSchemes('https');
Ip::make('last_seen_from')->v6();
```

### `Integer`

An integer attribute (JSON `type: integer`); casts to `int` both ways.

| Method | Adds |
|---|---|
| `min(int)` / `max(int)` | `Min` / `Max`. |
| `exclusiveMin(int)` / `exclusiveMax(int)` | `ExclusiveMin` / `ExclusiveMax`. |
| `multipleOf(int)` | `MultipleOf`. |
| `in(array $values)` | `In`. |

```php
Integer::make('rating')->min(1)->max(5);
```

### `Decimal`

A floating-point attribute (JSON `type: number`); casts to `float`. The bound
helpers accept `int|float`.

| Method | Adds |
|---|---|
| `min(int\|float)` / `max(int\|float)` | `Min` / `Max`. |
| `exclusiveMin(int\|float)` / `exclusiveMax(int\|float)` | `ExclusiveMin` / `ExclusiveMax`. |
| `multipleOf(int\|float)` | `MultipleOf`. |
| `in(array $values)` | `In`. |

```php
Decimal::make('price')->min(0)->multipleOf(0.01);
```

### `Boolean`

A boolean attribute; casts to `bool`. No type-specific constraints.

```php
Boolean::make('published');
```

### `DateTime`, `Date`, `Time`

`DateTime` serializes a `\DateTimeInterface` to a string and hydrates a string
back to a `\DateTimeImmutable`. The default format is ISO-8601
(`\DateTimeInterface::ATOM`). `Date` and `Time` are `DateTime` specialised to
`Y-m-d` and `H:i:s`.

| Method | Effect |
|---|---|
| `format(string)` | Override the serialization format string. |
| `before($bound)` / `after($bound)` | `Before` / `After` — accept a `\DateTimeInterface` or a `\Closure(): \DateTimeInterface` (closure bounds do not round-trip to JSON Schema). |
| `between($min, $max)` | `Between` (same bound forms). |
| `timezone(string ...$allowed)` | `Timezone` — restrict to IANA identifiers. |
| `useTimezone(string)` | Convert hydrated values into the given timezone before storing. |

```php
DateTime::make('publishedAt')->after(new \DateTimeImmutable('2000-01-01'));
Date::make('dob')->before(static fn () => new \DateTimeImmutable('today'));
```

### `ArrayList`

A zero-indexed array attribute (JSON `type: array`).

| Method | Adds / effect |
|---|---|
| `minItems(int)` / `maxItems(int)` | `MinItems` / `MaxItems`. |
| `uniqueItems()` | `UniqueItems`. |
| `each(Constraint ...$constraints)` | `Each` — applies the given constraints to every item. |
| `sorted()` | Sorts the list on serialization. |

```php
use haddowg\JsonApi\Resource\Constraint\MaxLength;

ArrayList::make('tags')->uniqueItems()->each(new MaxLength(32));
```

### `ArrayHash`

A JSON object attribute exposed as a PHP associative array (JSON `type: object`).

| Method | Adds / effect |
|---|---|
| `minProperties(int)` / `maxProperties(int)` | `MinProperties` / `MaxProperties`. |
| `sortKeys()` | Sort by key on serialization. |
| `sortValues()` | Sort by value on serialization (keys preserved). |

```php
ArrayHash::make('settings')->maxProperties(20);
```

### `Map`

`Map` exposes a nested JSON object in the resource attributes while spreading its
values across multiple **flat columns on the same domain object**. Each child
field reads and writes its own column; the child's name is the key inside the
nested object. Declare the children with `fields()`.

```php
Map::make('address')->fields(
    Str::make('street'),
    Str::make('city'),
    Str::make('postcode')->pattern('^[0-9A-Z ]+$'),
);
```

Top-level constraints on a `Map` are limited to presence (`required()` /
`nullable()`); structural constraints belong on the child fields.

> `Map::on($relation)` — spreading across a **related** model rather than the
> same one — is not currently supported.

## Relationships

A relationship is a field too: it appears in `fields()` and produces the resource
object's `relationships` member. The related resource serializes through the
[server's registry](server.md), so every participating type must be registered.
On hydration a relationship is filled from the request's parsed linkage — a
to-one stores the related id, a to-many the list of ids — not from a raw
attribute value.

All relations extend `Resource\Field\AbstractRelation` and, on top of the shared
field surface, share these methods:

| Method | Effect |
|---|---|
| `type(string ...$types)` | Declares the related resource type(s). One type for a monomorphic relation; several for a polymorphic one. Auto-appends a `RelationshipType` constraint. |
| `inverseType(string)` | Records the inverse relationship name on the related type (advisory metadata for adapters / OpenAPI). |
| `cannotEagerLoad()` | Marks the relation as not eager-loadable (advisory for data-layer adapters). |
| `withUriFieldName(string)` | Overrides the URI segment for this relationship (defaults to the field name). |
| `retainFieldName()` | Keeps the field name when it would otherwise be transformed. |

### `BelongsTo` / `HasOne`

To-one relations. `BelongsTo` models a foreign key on the owning model; `HasOne`
models it on the related model. They carry identical metadata — the distinction
is for data-layer adapters.

```php
BelongsTo::make('author')->type('authors')->required();
HasOne::make('profile')->type('profiles');
```

### `HasMany`

A to-many relation: a collection of related models. Adds collection bounds:

| Method | Adds |
|---|---|
| `minItems(int)` / `maxItems(int)` | `MinItems` / `MaxItems` on the linkage. |

```php
HasMany::make('comments')->type('comments')->maxItems(100);
```

### `BelongsToMany`

A pivot-backed to-many relation. Same serialization and constraint surface as
`HasMany`, plus a `fields()` method declaring the pivot (join-table) fields.
Pivot fields are **declare-only** — carried as metadata for data-layer adapters,
not validated by core. Validation of pivot fields is not currently supported.

```php
BelongsToMany::make('tags')->type('tags')->fields([
    'added_at' => 'datetime',
]);
```

### `MorphTo`

A polymorphic to-one relation: the related resource may be one of several
declared types. Declare them with `types()`; the related object's serializer is
resolved at runtime by its own `getType()`.

```php
MorphTo::make('commentable')->types('articles', 'videos');
```

## Related pages

- [Resources](resources.md) — declaring `fields()` and how they drive serialization/hydration.
- [Validation](validation.md) — the constraint vocabulary and the create/update context model.
- [Filters](filters.md) / [Sorts](sorts.md) — query-shaping metadata (`sortable()` feeds `allSorts()`).
- [Resources](serializers.md) / [Hydrators](hydrators.md) — the per-type customisation points.
