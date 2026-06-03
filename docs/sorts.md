# Sorts

A sort describes one `sort` key your collection endpoint accepts. Like a
[filter](filters.md), a sort in this library is **metadata only**: a value object
that names the sort key and the column it maps to, carrying no behaviour. Turning
a sort into an ordered query — an `ORDER BY`, a `usort`, a search-engine sort
clause — lives in an adapter-provided [`SortHandlerInterface`](adapters.md), not in the
sort itself. This keeps core decoupled from any data layer: there is no generic
`Query` interface, and the split mirrors exactly how [filters](filters.md) and
[field constraints](validation.md) work.

## Marking a field sortable

The common case needs no explicit sort declaration. Calling `->sortable()` on a
[field](fields.md) tells the Resource class to derive a sort for it automatically:

```php
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\DateTime;

final class ArticleResource extends AbstractResource
{
    public static string $type = 'articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->sortable(),
            DateTime::make('publishedAt', 'published_at')->sortable(),
        ];
    }
}
```

`AbstractResource::allSorts()` walks the fields and produces a `SortByField` for
each one marked `->sortable()`, keyed by the field name and targeting the field's
column (falling back to the field name). Those clients can now request
`?sort=title`, `?sort=-publishedAt` (a leading `-` means descending), or both
(`?sort=title,-publishedAt`).

## Computed and multi-column sorts

For sorts that don't map to a single sortable field — a computed expression, a
multi-column tiebreak — override `sorts()` and return the extra `Sort` value
objects. `allSorts()` merges them over the field-derived ones (a later entry with
the same key wins), so you only ever override `sorts()` for the non-trivial cases:

```php
use haddowg\JsonApi\Resource\Sort\SortByField;

public function sorts(): array
{
    return [
        SortByField::make('name', 'last_name'), // map a key to a different column
    ];
}
```

`SortByField` is the one built-in. It is a `final readonly` value object with a
`make($key, ?$column)` named constructor; the column defaults to the key. A custom
sort (see below) goes here too.

## The `SortInterface` contract

Every sort implements `Resource\Sort\SortInterface`, whose sole member is the key:

```php
interface SortInterface
{
    public function key(): string; // the sort key, without a leading '-'
}
```

The `-key` direction prefix is **not** part of the key — it is parsed off by the
request and handed to the handler as a separate descending flag. Concrete sorts
add their own public readonly fields (a column, an expression, …) that a handler
reads when it orders the query.

## Executing sorts

The metadata never orders anything on its own. To apply a sort you pass it, your
query, and the direction to a [`SortHandlerInterface`](adapters.md), which returns the
ordered query:

```php
public function apply(SortInterface $sort, mixed $query, bool $descending): mixed;
```

The requested sorts reach your handler through the request's parsed `sort` list.
From an [operation handler](server.md#operations) read it via
`queryParameters()->sort` (a `list<string>` with the leading `-` preserved), or
directly from the request with `JsonApiRequestInterface::getSorting()`. You then
match each requested key against the type's `allSorts()` and apply it in order:

```php
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;

$handler = new ArraySortHandler();
$resource = $server->resources()->resourceFor('articles'); // the AbstractResource
$allowed = [];
foreach ($resource->allSorts() as $sort) {
    $allowed[$sort->key()] = $sort;
}

$rows = $repository->all();
foreach ($operation->queryParameters()->sort as $field) {
    $descending = \str_starts_with($field, '-');
    $key = \ltrim($field, '-');
    if (isset($allowed[$key])) {
        $rows = $handler->apply($allowed[$key], $rows, $descending);
    }
}
```

Core ships `Resource\Sort\InMemory\ArraySortHandler` as a reference handler that
orders a PHP `list<array|object>` with a `usort`. It is used by the package's own
tests and serves as a worked example — it is **not** a production sort layer. A
real adapter pushes the ordering down to its data store.

If a sort value object reaches a handler that does not recognise it,
`Resource\Sort\UnsupportedSort` is thrown. Like
[`UnsupportedFilter`](filters.md#unsupported-filters), it is a **server
configuration error**, not a client error, so it renders as a `500` — a sort was
declared (or routed through) with no handler wired to execute it. It is an
`AbstractJsonApiException` like the rest of the [exception
hierarchy](exceptions.md), so the [error-handler middleware](middleware.md) turns
it into a JSON:API error document automatically, and it exposes the offending sort
via its public `$sort` property.

## Writing a custom sort

A custom sort is a value object implementing `SortInterface`, carrying whatever its handler
needs:

```php
use haddowg\JsonApi\Resource\Sort\SortInterface;

final readonly class SortByDistance implements SortInterface
{
    public function __construct(
        public string $key,
        public float $lat,
        public float $lng,
    ) {}

    public static function make(string $key, float $lat, float $lng): self
    {
        return new self($key, $lat, $lng);
    }

    public function key(): string
    {
        return $this->key;
    }
}
```

List it in a Resource class's `sorts()` like any built-in. For it to do anything, a
handler must recognise it — a `SortHandlerInterface` that receives a `SortInterface` it does not
know throws `UnsupportedSort` — so a custom sort and a handler that understands it
are written together. The handler side, including extending the reference handler,
is covered in [Adapters](adapters.md).

## Related pages

- [Fields](fields.md) — `->sortable()` and the field DSL.
- [Adapters](adapters.md) — the handler side: ordering your data layer.
- [Filters](filters.md) — the same metadata/handler split for the `filter` parameter.
- [Resource classes](resources.md) — `sorts()`, `allSorts()`, and the derivation rule.
