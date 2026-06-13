# Filtering collections

This page shows you how to declare which `filter[…]` parameters a collection
endpoint accepts, what the built-in catalogue gives you, and how to write a
custom filter for behaviour the built-ins don't cover. By the end you'll be able
to expose a text search, a boolean flag with a default, a set-membership filter,
and a geo predicate — each declared as metadata, executed by a data-layer handler.

In this library a filter is **metadata only**: a small value object that names
the `filter[<key>]` parameter and the column (or relationship) it targets, but
carries no behaviour. The work of turning a filter into a query — a `WHERE`
clause, an array predicate, a search call — lives in an adapter-provided
[`FilterHandlerInterface`](adapters.md), not in the filter itself. Here "adapter"
means a data-layer integration — a Doctrine/Eloquent backend or the in-memory
reference — not the request-lifecycle `Psr7ToOperationHandlerAdapter` from
[architecture](architecture.md). This is the
same metadata/handler split that [constraints](constraints.md) and
[sorts](sorts.md) use: core ships typed metadata, the data layer ships the
translators that execute it. The library never reads `filters()` and applies it
for you.

You declare filters here; a handler executes them. If you have not built one yet,
core ships a reference [`ArrayFilterHandler`](adapters.md#the-reference-handlers)
(and the example catalog's [`CriteriaApplier`](../examples/music-catalog/src/Data/CriteriaApplier.php)
wires it) — see [Adapters](adapters.md#the-reference-handlers) for the minimal
executing side. The rest of this page is purely the declaration side.

## A worked filter: searching tracks by title

The [`TrackResource`](../examples/music-catalog/src/Resource/TrackResource.php)
exposes a case-insensitive substring search on `title` plus an `explicit` flag
that defaults to off. You declare both by overriding `filters()`:

```php
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;

public function filters(): array
{
    // `like`: a case-insensitive substring match on title (the operator is the
    // third make() argument — there is no fluent operator() setter).
    // `explicit` coerces the request value to a real bool and defaults to false
    // when the key is absent. `genres` matches a membership set.
    return [
        Where::make('title', 'title', 'like'),
        Where::make('explicit')->asBoolean()->default(false),
        WhereIn::make('genres'),
    ];
}
```

A request applying the title filter:

```
GET /tracks?filter[title]=android&filter[explicit]=true
```

```json
{
  "data": [
    { "type": "tracks", "id": "2", "attributes": { "title": "Paranoid Android", "explicit": true, "…": "…" } }
  ]
}
```

The `explicit=true` here is doing real work: `Where::make('explicit')->asBoolean()->default(false)`
means a plain `GET /tracks` *excludes* explicit tracks, so to surface "Paranoid
Android" (the one explicit track) you have to override that default by presence.
The `default()` value round-trips as a real `bool` because `asBoolean()` coerces
the request value through `FILTER_VALIDATE_BOOLEAN` — `filter[explicit]=false`
selects the non-explicit tracks. The [`FiltersTest`](../examples/music-catalog/tests/FiltersTest.php)
witnesses the substring match, the boolean round-trip, and the default
exclusion/override pair.

`filters()` is purely declarative: it tells consumers (and a handler) which
`filter[<key>]` parameters are legal for this type and how each maps to a target.
The default is no filters.

## The `FilterInterface` contract

Every filter implements `Resource\Filter\FilterInterface`, whose sole member is
the parameter key:

```php
interface FilterInterface
{
    public function key(): string;
}
```

Concrete filters add their own `public readonly` fields (`column`, `operator`,
`delimiter`, …) that a handler reads. They are `final readonly` value objects
constructed through `make()`, with immutable `with`-style refinement helpers that
each return a new instance.

## The built-in catalogue

All built-ins live in `haddowg\JsonApi\Resource\Filter`. Each `make()` defaults
its target column (or relationship) to the filter key when you omit it.

| Filter | `make()` signature | Targets | Capabilities |
|---|---|---|---|
| `Where` | `make(string $key, ?string $column = null, string $operator = '=')` | a column | comparison; `singular()`, `deserializeUsing()`/`asBoolean()`, `default()` |
| `WhereIn` | `make(string $key, ?string $column = null)` | a column | value in a set; `singular()`, `delimiter()`, `default()` |
| `WhereNotIn` | `make(string $key, ?string $column = null)` | a column | negation of `WhereIn`; `singular()`, `delimiter()`, `default()` |
| `WhereIdIn` | `make(string $key = 'id', string $column = 'id')` | the id | id in a set; `delimiter()`, `default()` |
| `WhereIdNotIn` | `make(string $key = 'id', string $column = 'id')` | the id | negation of `WhereIdIn`; `delimiter()`, `default()` |
| `WhereNull` | `make(string $key, ?string $column = null)` | a column | column is null (presence-only) |
| `WhereNotNull` | `make(string $key, ?string $column = null)` | a column | column is not null (presence-only) |
| `WhereHas` | `make(string $key, ?string $relationship = null)` | a relationship | has a related record (presence-only) |
| `WhereDoesntHave` | `make(string $key, ?string $relationship = null)` | a relationship | negation of `WhereHas` (presence-only) |

`Where` carries the comparison operator as its third `make()` argument — `=`
(the default), `like`, `>`, `>=`, `<`, `<=`, `!=`, `===`. The reference
[`ArrayFilterHandler`](adapters.md#the-reference-handlers) maps each to a PHP
comparison (`like` is a case-insensitive `stripos`, matching what a SQL
`LIKE '%…%'` gives on common backends); a database adapter translates the same
operator strings into its own dialect.

### Refinement helpers

Each helper returns a new instance — the value objects are immutable — and only
the filters that carry the helper expose it:

| Helper | On | Effect |
|---|---|---|
| `singular()` | `Where`, `WhereIn`, `WhereNotIn` | marks a zero-to-one result (see [Singular filters](#singular-filters)) |
| `delimiter(string)` | `WhereIn`, `WhereNotIn`, `WhereIdIn`, `WhereIdNotIn` | overrides the default `,` a string value is split on |
| `deserializeUsing(\Closure)` | `Where` | a value transformer applied before comparison |
| `asBoolean()` | `Where` | a shortcut for `deserializeUsing()` coercing via `FILTER_VALIDATE_BOOLEAN` |
| `default(mixed)` | `Where`, `WhereIn`, `WhereNotIn`, `WhereIdIn`, `WhereIdNotIn` | a value to apply when the key is absent (see [Default values](#default-values)) |

```php
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;

Where::make('explicit')->asBoolean();
Where::make('createdAfter', column: 'created_at', operator: '>')
    ->deserializeUsing(static fn(mixed $v): \DateTimeImmutable => new \DateTimeImmutable((string) $v));
WhereIn::make('genres')->delimiter('|');
```

The presence-only filters (`WhereNull`, `WhereNotNull`, `WhereHas`,
`WhereDoesntHave`) carry no refinement helpers: their requested presence *is*
their semantics.

## Singular filters

A filter on a **unique** attribute — a slug, a UUID — matches at most one
resource. Marking it `singular()` declares that zero-to-one shape, but whether the
collapse to a single object actually happens is up to your handler: the metadata
says "this match is zero-to-one", and a handler that honours the flag returns a
**single resource object (or `null`) in `data`**, not an array. The reference
catalog handler narrows to the matching artist but still renders a collection — so
the responses below show the intended collapse a handler that honours the flag
would produce, not what the example catalog returns today. The
[`ArtistResource`](../examples/music-catalog/src/Resource/ArtistResource.php)
declares one on `slug`:

```php
use haddowg\JsonApi\Resource\Filter\Where;

public function filters(): array
{
    // singular(): GET /artists?filter[slug]=radiohead collapses a unique
    // match to a single resource object (or null), not a collection.
    return [
        Where::make('slug')->singular(),
    ];
}
```

```
GET /artists?filter[slug]=radiohead    →  { "data": { "type": "artists", "id": "1", … } }
GET /artists?filter[slug]=does-not-exist →  { "data": null }
GET /artists                            →  { "data": [ … ] }   // normal collection
```

The collapse applies only when the client actually sends the singular filter
(otherwise the usual zero-to-many collection is returned), and has no effect on
relationship endpoints. `singular()` is metadata: a filter declares it by
implementing the `Resource\Filter\SupportsSingular` capability interface
(`isSingular()`), and the collection handler reads it for an applied filter and
renders the first match (or `null`). A custom filter opts in by implementing
`SupportsSingular` itself.

The reference [`MusicCatalogHandler`](../examples/music-catalog/src/Handler/MusicCatalogHandler.php)
is the example here: it applies the `slug` predicate (narrowing to the matching
artist) but does not yet perform the single-resource collapse — the collapse is a
handler-level affordance and the metadata is in place for an adapter to honour.

## Default values

A value-carrying filter can declare a **default**: the value to apply when the
request doesn't carry its `filter[<key>]` parameter at all.

```php
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;

Where::make('explicit')->asBoolean()->default(false); // GET /tracks → explicit tracks excluded
WhereIn::make('tags')->default('new,featured');        // shaped as the request would carry it
```

A default is a **convenience the client can override, never a constraint it
cannot**: a requested key always wins, and it wins by *presence*
(`array_key_exists`) — an explicit empty or null value (`filter[explicit]=`)
still overrides the default. Anything the client must not be able to undo
(soft-delete exclusion, tenant scoping) belongs in your data layer, not the
filter vocabulary. Shape a set filter's default exactly as the request would
carry it — an array or a delimited string honouring the filter's `delimiter()`.

Defaulting filters implement the `Resource\Filter\HasDefaultValue` capability
interface (`hasDefault()` + `defaultValue()` — a dedicated flag, because `null`
is a legitimate default). The presence-only filters deliberately don't
participate: a "default" there would be an always-on constraint in disguise.

Like everything else about filters, a default is metadata — whoever matches
requested keys to declared filters folds the defaults in first, through
`FilterDefaults::apply()`, so the presence semantics are decided once and every
adapter agrees on them:

```php
use haddowg\JsonApi\Resource\Filter\FilterDefaults;

$requested = FilterDefaults::apply($request->getFiltering(), $resource->filters());
```

When two declared filters share a key, the first wins — the same first-match
rule a handler uses to resolve a requested key to its declared filter. A custom
filter opts in by implementing `HasDefaultValue` itself.

## Relationship-existence filters

`WhereHas` and `WhereDoesntHave` filter the collection by whether each row *has*
a related record, rather than by a column value. The
[`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php)
exposes albums that have at least one track:

```php
use haddowg\JsonApi\Resource\Filter\WhereHas;

public function filters(): array
{
    // WhereHas('tracks'): albums that have at least one related track. The
    // relationship key reads $album->tracks directly (a Doctrine adapter would
    // render an EXISTS subquery over the same relation).
    return [
        WhereHas::make('tracks'),
    ];
}
```

```
GET /albums?filter[tracks]=1   →  albums with at least one track
```

Core ships only the metadata. The reference `ArrayFilterHandler` tests the
related value for non-empty/non-null (the request value is irrelevant — presence
alone decides), and a Doctrine adapter renders an `EXISTS` / `NOT EXISTS`
subquery over the same relationship. See [Adapters](adapters.md#the-reference-handlers) for the
handler side.

## List and set values

A set filter (`WhereIn`, `WhereNotIn`, the id variants) treats its incoming
value as a **list** — either an already-array value
(`filter[genres][]=a&filter[genres][]=b`) or a delimited string
(`filter[genres]=a,b`, split on the configured `delimiter()`). The split happens
in the handler: the reference `ArrayFilterHandler` consults `$filter->delimiter`
(defaulting to `,`) and treats an array value as already-split.

## Writing a custom filter

When the built-ins don't cover a predicate, you write a value object
implementing `FilterInterface` and carry whatever fields your handler needs to
execute it. The [`WithinRadius`](../examples/music-catalog/src/Filter/WithinRadius.php)
geo filter names the latitude/longitude columns to read off each row:

```php
use haddowg\JsonApi\Resource\Filter\FilterInterface;

final readonly class WithinRadius implements FilterInterface
{
    public function __construct(
        public string $key,
        public string $latColumn,
        public string $lngColumn,
    ) {}

    public static function make(string $key, string $latColumn = 'latitude', string $lngColumn = 'longitude'): self
    {
        return new self($key, $latColumn, $lngColumn);
    }

    public function key(): string
    {
        return $this->key;
    }
}
```

List it in a Resource's `filters()` like any built-in. For it to *do* anything,
a handler must recognise it — the reference `ArrayFilterHandler` doesn't, so the
catalog's [`CriteriaApplier`](../examples/music-catalog/src/Data/CriteriaApplier.php)
carries a matching execution arm, exactly as a Doctrine adapter would add an arm
of its own:

```php
$rows = $filter instanceof WithinRadius
    ? $this->withinRadius($rows, $filter, $value)
    : $this->delegateFilter($filter, $rows, $value);
```

A custom filter and the handler that understands it are written together: a
`FilterHandlerInterface` that receives a `FilterInterface` it doesn't know throws
[`UnsupportedFilter`](#unsupported-filters). The handler side — including the
worked `withinRadius()` arm — is covered in [Adapters](adapters.md#writing-a-handler-for-a-real-store).

## Unsupported filters

When a `FilterInterface` reaches a `FilterHandlerInterface` that doesn't
recognise it, `Resource\Filter\UnsupportedFilter` is thrown. This is a **server
configuration error**, not a client error — a filter was declared (or routed
through) with no handler wired to execute it — so it renders as a **`500`**. It
is an `AbstractJsonApiException` like the rest of the
[exception hierarchy](errors-and-exceptions.md), so the
[error-handler middleware](middleware.md) turns it into a JSON:API error
document automatically. It exposes the offending filter via its public `$filter`
property.

An *undeclared* filter key (one no resource lists) is a different case: it is
silently ignored. The library never auto-applies filters, and a handler only
dispatches the declared VOs it is handed — so `GET /artists?filter[withinRadius]=51.5`
against a resource that doesn't declare `withinRadius` returns the full
collection, unnarrowed.

## Reading requested filters off the operation

From an [operation handler](operations.md#queryparameters) you read the requested
`filter[…]` map two ways:

- `$operation->queryParameters()->filter` — an `array<string, mixed>` keyed by
  filter key, the spec-shaped projection on the operation's `QueryParameters`
  value object;
- `JsonApiRequestInterface::getFiltering()` — the same map straight off the
  request (what `QueryParameters` reads from).

You then match each present key to the declared filter and hand both to the
handler. The [`CriteriaApplier`](../examples/music-catalog/src/Data/CriteriaApplier.php)
shows the full loop: fold defaults in with `FilterDefaults::apply()`, index the
declared filters by key (first wins for a shared key), then for each requested
key look up its declared filter and dispatch:

```php
$requested = $foldDefaults
    ? FilterDefaults::apply($request->getFiltering(), $resource->filters())
    : $request->getFiltering();

foreach ($requested as $key => $value) {
    $filter = $declared[$key] ?? null;
    if ($filter === null) {
        continue; // an undeclared key is silently ignored
    }
    // … dispatch $filter + $value to the handler …
}
```

## Next / See also

- [Adapters](adapters.md) — the handler side: applying filters against your data
  layer, the reference `ArrayFilterHandler`, and a worked custom-filter arm.
- [Sorts](sorts.md) — the same metadata/handler split for the `sort` parameter.
- [Pagination](pagination.md) — windowing a filtered, sorted collection.
- [Resource classes](resources.md) — declaring `filters()` on a resource type.
- [Validation](constraints.md) — the constraint metadata this pattern mirrors.
