# Sorts

A sort declares one key your collection endpoint accepts in the `sort` query
parameter, so clients can order results — `GET /tracks?sort=title,-trackNumber`.
This page covers marking a field sortable, the one built-in sort, writing a
computed sort, and the handler contract that turns requested sorts into an
ordered query.

Like a [filter](filters.md), a sort in this library is **metadata only**: a value
object that names the sort key and the column it maps to, carrying no behaviour.
Turning a sort into an ordered query — an `ORDER BY`, a `usort`, a search-engine
sort clause — lives in an adapter-provided [`SortHandlerInterface`](adapters.md),
not in the sort itself. This keeps core decoupled from any data layer: there is no
generic query interface, and the split mirrors exactly how [filters](filters.md)
and [field constraints](constraints.md) work.

This page is the **declaration side** — marking fields sortable and writing sort
value objects. If you followed the getting-started handler and just want
`?sort=title` working end to end, the smallest executing side is the reference
[`ArraySortHandler`](../src/Resource/Sort/InMemory/ArraySortHandler.php) wired up
the way the catalog's
[`CriteriaApplier`](../examples/music-catalog/src/Data/CriteriaApplier.php) does it
(shown under [Executing sorts](#executing-sorts)); see [Adapters](adapters.md) for
pushing the ordering down to a real store.

## Marking a field sortable

The common case needs no explicit sort declaration. Calling `->sortable()` on a
[field](fields.md) tells the resource to derive a sort for it automatically. The
[`TrackResource`](../examples/music-catalog/src/Resource/TrackResource.php) marks
two fields sortable:

```php
public function fields(): array
{
    return [
        Id::make(),
        \haddowg\JsonApi\Resource\Field\Str::make('title')->required()->sortable(),
        \haddowg\JsonApi\Resource\Field\Integer::make('trackNumber')->min(1)->sortable(),
        // …
    ];
}
```

Clients can now request `?sort=title`, `?sort=-trackNumber` (a leading `-` means
descending), or both at once — `?sort=trackNumber,-title` — where the order of
the keys is the order of significance: the first is the primary sort, later keys
break ties.

```http
GET /tracks?sort=trackNumber,-title
```

orders primarily by `trackNumber` ascending, then by `title` descending within
each `trackNumber`. ([`SortsTest`](../examples/music-catalog/tests/SortsTest.php)
asserts exactly this ordering.)

## `allSorts()`: derived plus explicit

`AbstractResource::allSorts()` is the full set of sorts a resource accepts. It
walks the fields and produces a `SortByField` for each one marked `->sortable()`
(keyed by the field name, targeting the field's column and falling back to the
field name), then merges in whatever `sorts()` returns. The merge is keyed by sort
key, **later entries win**, so an explicit `sorts()` entry overrides a
field-derived one with the same key. A [sort family](#sort-families-keys-matched-by-prefix)
is the exception: it never answers its bare prefix, so it sits beside an exact sort
with the same key instead of replacing it.

```php
// AbstractResource::allSorts() — derived first, explicit sorts() merged over them
foreach ($this->allFields() as $field) {
    if ($field->isSortable()) {
        $derived[] = SortByField::make($field->name(), $field->column() ?? $field->name());
    }
}

return SortMatcher::merge($derived, $this->sorts());
```

You only ever override `sorts()` for the non-trivial cases below — for the common
field sort, `->sortable()` is enough.

## `SortByField`: the one built-in

[`SortByField`](../src/Resource/Sort/SortByField.php) is a `final readonly` value
object with a `make($key, ?$column)` named constructor; the column defaults to the
key. You rarely construct it by hand — `->sortable()` derives it for you. Declare
one explicitly in `sorts()` only when the sort key is **not** the field name, or
when you want a column override:

```php
use haddowg\JsonApi\Resource\Sort\SortByField;

public function sorts(): array
{
    return [
        SortByField::make('name', 'last_name'), // expose ?sort=name, order by the last_name column
    ];
}
```

## `defaultSort()`: the order with no `?sort`

`allSorts()` only governs which sorts a collection *accepts*. With no `sort`
parameter a collection is returned in **storage order**, which also makes
pagination non-deterministic. Override `defaultSort()` to declare a default order
applied **only when the request carries no `?sort`** — an explicit `?sort=`
overrides it entirely (the default is never appended to a requested sort). The
[`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php) orders
albums newest-first by default:

```php
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

public function defaultSort(): array
{
    return [
        new SortDirective(SortByField::make('releasedAt'), descending: true),
    ];
}
```

`GET /albums` (no `?sort`) now orders by `releasedAt` descending; `GET
/albums?sort=title` orders by `title` ascending and ignores the default.

Each entry is a [`SortDirective`](#executing-sorts) — the same `SortInterface` +
direction pair a data layer builds for a requested sort, most significant first —
so a default flows through the resource's [sort handler](#executing-sorts) on the
*same* path as a requested sort, with no new execution arm. A default must
therefore name a sort the handler can execute (a `SortByField` for the reference
[`ArraySortHandler`](#the-reference-arraysorthandler), or a custom sort with a
handler arm). `defaultSort()` defaults to `[]` — no default order. The data layer
falls back to it whenever the request's parsed `sort` list is empty:

```php
$requested = $request->getSorting();
if ($requested === []) {
    $directives = $resource->defaultSort(); // [] ⇒ storage order
    // … apply $directives through the same handler a requested sort uses
}
```

## The `SortInterface` contract

Every sort implements
[`Resource\Sort\SortInterface`](../src/Resource/Sort/SortInterface.php), whose sole
member is the key:

```php
interface SortInterface
{
    public function key(): string; // the sort key, without a leading '-'
}
```

The `-key` direction prefix is **not** part of the key — it is parsed off the
request and handed to the handler as a separate descending flag. Concrete sorts
add their own public readonly fields (a column, an expression, …) that a handler
reads when it orders the query.

## Computed and multi-column sorts

For sorts that don't map to a single sortable field — a computed expression, an
ordering across columns — write a custom `SortInterface` and return it from
`sorts()`. The [`ArtistResource`](../examples/music-catalog/src/Resource/ArtistResource.php)
exposes `?sort=trackCount`, an ordering by a computed `trackCount` that has no
single backing column:

```php
public function sorts(): array
{
    return [
        new TrackCountSort(),
    ];
}
```

[`TrackCountSort`](../examples/music-catalog/src/Sort/TrackCountSort.php) is a
plain value object carrying whatever its handler needs — here, the key and the
property to read:

```php
use haddowg\JsonApi\Resource\Sort\SortInterface;

final readonly class TrackCountSort implements SortInterface
{
    public function __construct(
        public string $key = 'trackCount',
        public string $column = 'trackCount',
    ) {}

    public function key(): string
    {
        return $this->key;
    }
}
```

For it to do anything, a handler must recognise it — see *Executing sorts* below.
A custom sort and the handler arm that understands it are written together.

## Sort families: keys matched by prefix

Some sort keys are data rather than vocabulary: a campaign's placeholder values,
custom fields, EAV attributes, members of a JSON column. You can't list them in
`sorts()` because they differ per record set, but clients still want
`?sort=value.cap,-value.end_date`.

A [`SortFamily`](../src/Resource/Sort/SortFamily.php) answers every key that
starts with a prefix and a dot. You give it the prefix and a resolver that turns
the rest of the key, the suffix, into a concrete sort:

```php
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Sort\SortFamily;
use haddowg\JsonApi\Resource\Sort\SortInterface;

public function sorts(): array
{
    return [
        SortFamily::make('value', fn(string $suffix, JsonApiRequestInterface $request): ?SortInterface
            => $this->placeholders->exists($suffix)
                ? new PlaceholderValueSort('value.' . $suffix, $suffix)
                : null),
    ];
}
```

`?sort=-value.end_date` calls the resolver with `end_date` and sorts descending by
the sort it returns. The rules:

- The suffix is everything after `<prefix>.`, dots included, so `value.a.b` hands
  the resolver `a.b`. The bare prefix never matches: `?sort=value` is unrecognized
  unless an exact sort is keyed `value`, and the two can sit side by side.
- Returning `null` rejects the key with the usual `400` `SortParamUnrecognized`,
  naming the full key.
- The sort you return must be keyed by the full requested key (`value.end_date`
  above). Anything else is a `\LogicException` naming the family, since a data
  layer and a cursor token both identify the sort by that key.
- An exact key always wins over a family. When two families' prefixes both fit,
  the longer prefix decides, and its `null` is final.

By default any non-empty suffix without a comma is accepted. Narrow it with
`withSuffixPattern()`, a regular-expression fragment the whole suffix must match.
A suffix that fails it is rejected without calling the resolver, and the pattern
also lands in the [OpenAPI document](openapi.md#sort-families-x-sort-families).
Stick to syntax PCRE and ECMA-262 share:

```php
SortFamily::make('value', $resolver)->withSuffixPattern('[a-z][a-z_]*');
```

A family is never handed to a sort handler. Matching replaces it with the sort its
resolver returns, so the handler arm for `PlaceholderValueSort` is the same arm a
fixed custom sort would need. Families work wherever a sort key does: the primary
`sort`, a relation's `withSorts()`, the
[Relationship Queries](profiles/relationship-queries.md) profile's `relatedQuery[<path>][sort]`, and a
`defaultSort()` directive whose sort carries a key a family answers.

To write your own family, implement
[`SortFamilyInterface`](../src/Resource/Sort/SortFamilyInterface.php): `key()`
returns the prefix, `suffixPattern()` the fragment and `resolve($suffix, $request)`
the sort.

## Executing sorts

The metadata never orders anything on its own. Execution lives in a
[`SortHandlerInterface`](adapters.md), and the whole ordered sort is applied in
**one** call:

```php
/** @param list<SortDirective> $sorts */
public function apply(array $sorts, mixed $query): mixed;
```

This is the key shape: the handler receives the **full ordered list** of
directives — most significant first — not one directive at a time. Sorting does
not compose commutatively, and the correct way to combine keys differs per data
layer: SQL appends `ORDER BY` terms in significance order, while an in-memory
re-sort must compare keys in a single cascading comparator. Handing the handler
the full list lets each adapter compose natively and keeps the request's first
sort field the primary key everywhere, as the spec requires.

Each element is a
[`SortDirective`](../src/Resource/Sort/SortDirective.php) — a `final readonly`
pair of the matched sort and its direction:

```php
final readonly class SortDirective
{
    public function __construct(
        public SortInterface $sort,
        public bool $descending,
    ) {}
}
```

### Reading the requested sorts

The requested sorts reach you through the request's parsed `sort` list. From an
[operation handler](operations.md#queryparameters) read it via `queryParameters()->sort`
(a `list<string>` with the leading `-` preserved), or directly from the request
with `JsonApiRequestInterface::getSorting()`. You match each requested key against
the type's `allSorts()`, build a `SortDirective` per match, and hand the whole
list to the handler.

[`SortMatcher`](../src/Resource/Sort/SortMatcher.php) does the matching the way
the rest of the library does, sort families included:

```php
use haddowg\JsonApi\Resource\Sort\SortMatcher;

// 400 SortingUnsupported / SortParamUnrecognized on a bad key
$directives = SortMatcher::directives($request->getSorting(), $resource->allSorts(), $request);

// one key, or null when nothing declared answers it
$sort = SortMatcher::match($resource->allSorts(), 'value.cap', $request);

// a related endpoint's vocabulary: the related type's sorts, then the relation's
$sorts = SortMatcher::merge($relatedResource->allSorts(), $relation->sorts());
```

The request is optional for exact keys, but a family needs it to resolve one, so
matching a family key without it is a `\LogicException`. `merge()` lets a later
sort replace an earlier one with the same key, and keeps a family beside an exact
sort sharing its key.

The catalog's
[`CriteriaApplier`](../examples/music-catalog/src/Data/CriteriaApplier.php) does
exactly this:

```php
$requested = $request->getSorting();

/** @var array<string, SortInterface> $allSorts */
$allSorts = [];
foreach ($resource->allSorts() as $sort) {
    $allSorts[$sort->key()] = $sort;
}

$directives = [];
foreach ($requested as $entry) {
    $descending = \str_starts_with($entry, '-');
    $key = $descending ? \substr($entry, 1) : $entry;

    $sort = $allSorts[$key] ?? null;
    if ($sort === null) {
        continue; // an unknown sort key is skipped, not an error
    }

    $directives[] = new SortDirective($sort, $descending);
}

$sorted = $this->sorts->apply($directives, $rows);
```

A `sort` key matching no declared or derived sort is ignored — the applier skips
it and leaves the order untouched, rather than rejecting the request.

That silent-ignore is **key-level** — an unrecognized key *inside* the `sort`
family. The `sort` **family** itself is always recognized, so it never trips the
[strict query-parameter validation](content-negotiation.md#strict-query-parameter-validation-on-by-default)
`Server` runs by default: an unrecognized query-parameter *family* (a misspelled
`?srot=...`, an unregistered custom parameter) is a `400` `QueryParamUnrecognized`,
distinct from this key-level tolerance within a recognized family.

### The reference `ArraySortHandler`

Core ships
[`Resource\Sort\InMemory\ArraySortHandler`](../src/Resource/Sort/InMemory/ArraySortHandler.php)
as a reference handler that orders a PHP `list<array|object>`. It collects the
directives' columns and runs one `usort` whose comparator cascades through them in
significance order, so the first sort field stays primary:

```php
public function apply(array $sorts, mixed $query): mixed
{
    $columns = [];
    foreach ($sorts as $directive) {
        $sort = $directive->sort;
        if (!$sort instanceof SortByField) {
            throw new UnsupportedSort($sort);
        }
        $columns[] = [$sort->column, $directive->descending];
    }
    // … one usort, comparing each column in turn until one differs
}
```

It is used by the package's own tests and serves as the canonical adapter
example — it is **not** a production sort layer. A real adapter pushes the ordering
down to its data store (see [Adapters](adapters.md)). Note that it understands
only `SortByField`: a custom sort like `TrackCountSort` would throw
`UnsupportedSort` if it reached the handler, so the `CriteriaApplier` executes the
computed sort in a pre-arm before delegating the field sorts:

```php
// The reference ArraySortHandler only understands SortByField, so a computed
// sort is executed here before the field sorts are delegated.
foreach ($directives as $directive) {
    if ($directive->sort instanceof TrackCountSort) {
        return $this->sortByTrackCount($rows, $directive->sort, $directive->descending);
    }
}
```

## Unsupported sorts

If a sort value object reaches a handler that does not recognise it,
[`Resource\Sort\UnsupportedSort`](../src/Resource/Sort/UnsupportedSort.php) is
thrown. Like [`UnsupportedFilter`](filters.md#unsupported-filters), it is a
**server configuration error**, not a client error, so it renders as a `500` — a
sort was declared (or routed through) with no handler wired to execute it. It is
an `AbstractJsonApiException` like the rest of the [exception
hierarchy](errors-and-exceptions.md), so the [error-handler middleware](middleware.md) turns
it into a JSON:API error document automatically, and it exposes the offending sort
via its public `$sort` property.

This is distinct from
[`SortParamUnrecognized`](errors-and-exceptions.md), which is a *client* `400` for a `sort`
parameter the server rejects outright; `UnsupportedSort` is purely about a handler
gap on the server side.

### Sorting a cursor page by a custom sort

[Cursor pagination](pagination.md#cursor-pagination) walks a total order and
writes the boundary row's sort values into each cursor token, so a sort has to
say what its value is for a row. A `SortByField` reads its column. A custom sort
opts in by implementing
[`KeysetSortInterface`](../src/Resource/Sort/KeysetSortInterface.php):

```php
final readonly class PlaceholderValueSort implements KeysetSortInterface
{
    public function __construct(public string $key, public string $placeholder) {}

    public function key(): string
    {
        return $this->key;
    }

    public function keysetValue(mixed $row): mixed
    {
        return $row->placeholderValue($this->placeholder); // scalar, null or a DateTime
    }
}
```

`keysetValue()` runs only for the rows a token is minted from. The data layer's
arm for the sort still does the ordering and the "strictly after this value"
comparison, with null ordered as the largest value; see
[the keyset execution toolkit](pagination.md#the-keyset-execution-toolkit). A
custom sort that doesn't implement the interface stays a `500` `UnsupportedSort`
under cursor pagination.

## Next / see also

- [Fields](fields.md) — `->sortable()` and the field DSL.
- [Adapters](adapters.md) — the handler side: ordering a real data store.
- [Filters](filters.md) — the same metadata/handler split for the `filter` parameter.
- [Pagination](pagination.md) — windowing the ordered collection.
- [Resource classes](resources.md) — `sorts()`, `allSorts()`, and the derivation rule.
- [Relations](relations.md#relation-scoped-filters-and-sorts) — scoping sorts (and filters) to a related to-many collection with `withSorts()` / `withFilters()`.
