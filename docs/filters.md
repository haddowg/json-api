# Filters

A filter describes one `filter[…]` query parameter your collection endpoint
accepts. In this library a filter is **metadata only**: a small value object that
names the parameter key and the column it targets, but carries no behaviour. The
work of turning a filter into a query — a `WHERE` clause, an array predicate, a
search call — lives in an adapter-provided [`FilterHandler`](adapters.md), not in
the filter itself. This keeps core decoupled from any data layer: there is no
generic `Query` interface and no assumption that you are using a database at all.

This split mirrors the way [field constraints](validation.md) work — core ships
typed metadata, adapters ship the translators that execute it. See
[Adapters](adapters.md) for the pattern in full and a worked handler.

## Declaring the filters a type accepts

A Resource class lists the filters it exposes by overriding `filters()`. Each entry is a
filter value object built with its `make()` named constructor:

```php
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;

final class ArticleResource extends AbstractResource
{
    public static string $type = 'articles';

    public function fields(): array
    {
        return [Id::make(), Str::make('title')->required()];
    }

    public function filters(): array
    {
        return [
            WhereIdIn::make(),
            Where::make('title', operator: 'like'),
            WhereIn::make('status'),
        ];
    }
}
```

`filters()` is declarative: it tells consumers (and a handler) which
`filter[<key>]` parameters are legal for this type and how each maps to a column.
The default is no filters. The library does **not** read `filters()` and apply it
for you — your collection handler asks its `FilterHandler` to apply each filter
whose key is present in the request.

The filter value objects reach your handler through the request's parsed
`filter[…]` map. From an [operation handler](server.md#operations) you read it via
the operation's `queryParameters()->filter` (an `array<string, mixed>` keyed by
filter key), or directly from the request with
`JsonApiRequestInterface::getFiltering()`. You then match each present key to the
declared filter and hand both to the handler — see [Adapters](adapters.md#filters)
for the loop.

## The `Filter` contract

Every filter implements `Resource\Filter\Filter`, whose sole member is the key:

```php
interface Filter
{
    public function key(): string;
}
```

Concrete filters add their own public readonly fields (`column`, `operator`,
`delimiter`, …) that a handler reads. They are all `final readonly` value objects
constructed through `make()`, with immutable `with`-style refinement helpers that
each return a new instance.

## Built-in filters

All built-ins live in `haddowg\JsonApi\Resource\Filter`. Each `make()` defaults
the target column/relationship to the filter key when you omit it.

| Filter | `make()` signature | Targets | Notes |
|---|---|---|---|
| `Where` | `make(string $key, ?string $column = null, string $operator = '=')` | a column | Comparison with an operator (`=`, `like`, `>`, …). |
| `WhereIn` | `make(string $key, ?string $column = null)` | a column | Value in a set. |
| `WhereNotIn` | `make(string $key, ?string $column = null)` | a column | Negation of `WhereIn`. |
| `WhereIdIn` | `make(string $key = 'id', string $column = 'id')` | the id | Id in a set; the common case, shipped as a dedicated type. |
| `WhereIdNotIn` | `make(string $key = 'id', string $column = 'id')` | the id | Negation of `WhereIdIn`. |
| `WhereNull` | `make(string $key, ?string $column = null)` | a column | Column is null. |
| `WhereNotNull` | `make(string $key, ?string $column = null)` | a column | Column is not null. |
| `WhereHas` | `make(string $key, ?string $relationship = null)` | a relationship | Has a related record; the adapter interprets the traversal. |
| `WhereDoesntHave` | `make(string $key, ?string $relationship = null)` | a relationship | Negation of `WhereHas`. |

### Refinement helpers

`Where`, `WhereIn` and `WhereNotIn` carry fluent helpers (each returns a new
instance — the value objects are immutable):

- **`singular()`** — on `Where`, `WhereIn`, `WhereNotIn`. Marks the filter as
  accepting a **single value** rather than a comma-delimited list. See below.
- **`delimiter(string $delimiter)`** — on `WhereIn`, `WhereNotIn`, `WhereIdIn`,
  `WhereIdNotIn`. Overrides the default `,` separator a string value is split on.
- **`deserializeUsing(\Closure $deserialize)`** — on `Where`. Supplies a value
  transformer applied to the incoming value before comparison.
- **`asBoolean()`** — on `Where`. A shortcut for `deserializeUsing()` that coerces
  the value to a boolean via `FILTER_VALIDATE_BOOLEAN` (handy for
  `filter[published]=true`).

```php
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;

Where::make('published')->asBoolean();
Where::make('createdAfter', column: 'created_at', operator: '>')
    ->deserializeUsing(static fn(mixed $v): \DateTimeImmutable => new \DateTimeImmutable((string) $v));
WhereIn::make('tags')->delimiter('|');
```

## Singular vs list filters

A set filter (`WhereIn`, `WhereNotIn`, the id variants) treats its incoming value
as a **list**: either an already-array value (`filter[tags][]=a&filter[tags][]=b`)
or a delimited string (`filter[tags]=a,b`, split on the configured delimiter).
Marking a filter `singular()` declares the opposite — the value is one scalar and
should not be split. This matters when a value can legitimately contain the
delimiter (`filter[name]=Smith, Jr.`); a `singular()` filter passes it through
intact.

The split itself happens in the handler. The flag is metadata the handler reads —
the reference [`ArrayFilterHandler`](adapters.md#a-worked-handler) consults
`$filter->delimiter` when splitting and treats an array value as already-split.

## Writing a custom filter

A custom filter is just a value object implementing `Filter`. Carry whatever
fields your handler needs to execute it:

```php
use haddowg\JsonApi\Resource\Filter\Filter;

final readonly class FullTextSearch implements Filter
{
    public function __construct(
        public string $key,
        public string $index,
    ) {}

    public static function make(string $key, string $index): self
    {
        return new self($key, $index);
    }

    public function key(): string
    {
        return $this->key;
    }
}
```

List it in a Resource class's `filters()` like any built-in. For it to do anything, a
handler must recognise it: a `FilterHandler` that receives a `Filter` it does not
know throws [`UnsupportedFilter`](#unsupported-filters), so a custom filter and a
handler that understands it are written together. The handler side — including a
worked example extending the reference handler — is covered in
[Adapters](adapters.md#writing-a-custom-handler).

## Unsupported filters

When a `Filter` reaches a `FilterHandler` that does not recognise it,
`Resource\Filter\UnsupportedFilter` is thrown. This is a **server configuration
error**, not a client error — it means a filter was declared (or sent through)
with no handler wired to execute it — so it renders as a `500`. It is an
`AbstractJsonApiException` like the rest of the [exception hierarchy](exceptions.md),
so the [error-handler middleware](middleware.md) turns it into a JSON:API error
document automatically. It exposes the offending filter via its public
`$filter` property.

## Related pages

- [Adapters](adapters.md) — the handler side: applying filters against your data layer.
- [Sorts](sorts.md) — the same metadata/handler split for the `sort` parameter.
- [Resource classes](resources.md) — declaring `filters()` on a resource type.
- [Validation](validation.md) — the constraint metadata this pattern mirrors.
