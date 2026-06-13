# Writing a data-layer adapter

This page is for anyone wiring filters, sorts, and constraints up to a real
store. You will learn how the library keeps itself storage-agnostic — it ships
typed metadata that *describes* a query but never *runs* it — and how to write
the handlers and translators that do the running, narrowing the abstract query
to your own data layer (a `QueryBuilder`, a PHP array, a search request).

## The core principle: metadata describes, adapters execute

The library draws a deliberate line between **metadata** and **execution**. The
core ships typed value objects describing intent — [constraints](constraints.md)
(`->required()`, `->maxLength()`), [filters](filters.md) (`Where`, `WhereIn`),
[sorts](sorts.md) (`SortByField`) — and a [resource class](resources.md)
declares which it accepts. But the core **never runs any of them** against your
data. Execution lives in **handlers** and **translators** an adapter provides: a
filter handler turns a `FilterInterface` into a query predicate, a sort handler
turns a `SortInterface` into an ordering, a constraint translator turns a
`ConstraintInterface` into your validator's native rule.

The reason for the split is decoupling — the core knows nothing about your
storage. There is deliberately **no generic `Query` interface**: a handler's
query parameter is `mixed`, so a Doctrine handler narrows it to a
`QueryBuilder`, an in-memory handler to an array, a search adapter to its own
request object, and the core couples to none of them.

Note the asymmetry up front: filters and sorts execute through core *handler
interfaces*, but constraints translate through an interface *you define* — the
core ships no `ConstraintTranslatorInterface` (see
[Constraints follow the same split](#constraints-follow-the-same-split) below).

## The metadata contracts

Each metadata kind is a one-method interface; the concrete value objects add
public readonly fields a handler reads.

```php
// Resource\Constraint\ConstraintInterface
public function context(): Context;   // create / update / both

// Resource\Filter\FilterInterface
public function key(): string;        // the filter[<key>] this responds to

// Resource\Sort\SortInterface
public function key(): string;        // the sort key (no leading '-')
```

A resource declares which it accepts: field [constraints](constraints.md) inline
on each field, [`filters()`](filters.md) for the filter list, and field
`->sortable()` / [`sorts()`](sorts.md) for the sort list. None of those
declarations execute on their own — they are inert until an adapter reads them.

## The handler contracts

Filters and sorts are executed by handlers, both templated on the query type so
no data layer leaks into the core:

```php
/** @template TQuery */
interface FilterHandlerInterface
{
    /** @param TQuery $query @return TQuery */
    public function apply(FilterInterface $filter, mixed $query, mixed $value): mixed;
}

/** @template TQuery */
interface SortHandlerInterface
{
    /** @param list<SortDirective> $sorts @param TQuery $query @return TQuery */
    public function apply(array $sorts, mixed $query): mixed;
}
```

The two shapes differ for a reason. A filter handler receives **one** filter,
its request-supplied `$value`, and the query; you call it once per requested
filter, folding each predicate onto the query. A sort handler receives the
**whole ordered list** of [`SortDirective`](sorts.md)s — most significant first
— in a single call, never directive by directive. Sorting does not compose
commutatively, and the correct way to combine keys differs per data layer: SQL
appends `ORDER BY` terms in significance order, while an in-memory re-sort must
compare keys in one cascading comparator. Handing the handler the full list lets
each adapter compose natively and keeps the request's first sort field the
primary key everywhere, as the spec requires.

A handler matches on the concrete metadata type and produces the native query
operation. When it meets a value object it does not recognise it **throws** —
`Resource\Filter\UnsupportedFilter` or `Resource\Sort\UnsupportedSort`. Both are
**server-configuration errors**, not client errors, so they render as a `500`: a
filter or sort was declared (or routed through) with no handler wired to execute
it. They are `AbstractJsonApiException`s, so the [error-handler
middleware](middleware.md) renders them as JSON:API error documents
automatically, and each exposes the offending VO (`$filter` / `$sort`).

## The reference handlers

The core ships two worked handlers operating on a PHP `list<array|object>`:
[`ArrayFilterHandler`](../src/Resource/Filter/InMemory/ArrayFilterHandler.php)
and [`ArraySortHandler`](../src/Resource/Sort/InMemory/ArraySortHandler.php).
They power the package's own integration tests and serve as the canonical
example for adapter authors. They are **not** a production query layer — they
filter and sort in memory with no indexing; a real adapter pushes the predicate
and the ordering down to its store.

Both read model values through [`Accessor::get`](../src/Resource/Field/Accessor.php),
a framework-agnostic reader that works on an array key, a public property, or a
conventional `getXxx()` / `isXxx()` accessor — so the same handler reads a plain
array row or a domain object.

### ArrayFilterHandler — the operator semantics

`ArrayFilterHandler::apply` builds an `array_filter` predicate from the matched
value object's fields and `array_values`-reindexes the survivors. The `match`
arm is the reference for what each built-in filter means:

```php
private function predicate(FilterInterface $filter, mixed $value): \Closure
{
    return match (true) {
        $filter instanceof Where => $this->where($filter, $value),
        $filter instanceof WhereIn => $this->whereIn($filter->column, $this->toList($value, $filter->delimiter), false),
        $filter instanceof WhereNotIn => $this->whereIn($filter->column, $this->toList($value, $filter->delimiter), true),
        // … WhereIdIn / WhereIdNotIn / WhereNull / WhereNotNull …
        $filter instanceof WhereHas => fn(mixed $row): bool => $this->hasRelation($row, $filter->relationship),
        $filter instanceof WhereDoesntHave => fn(mixed $row): bool => !$this->hasRelation($row, $filter->relationship),
        default => throw new UnsupportedFilter($filter),
    };
}
```

The `Where` arm covers the comparison operators. Most are the obvious PHP
comparison; two are worth pinning down:

| `Where::$operator` | semantics |
| --- | --- |
| `=`, `==` | loose equality (`==`) |
| `===` | strict equality (`===`) |
| `!=`, `<>` | loose inequality |
| `>` `>=` `<` `<=` | the ordered comparisons |
| `like` | case-insensitive ASCII substring (`\stripos(...) !== false`) — the `LIKE '%…%'` reference behaviour a SQL adapter should match |

Before comparing, `Where`'s optional `deserialize` closure transforms the
incoming value — that is how `->asBoolean()` coerces `filter[explicit]=true` to a
real `bool`. The set filters (`WhereIn` and friends) split the value into a list
with `toList()`: an array passes through, a string splits on the filter's
`delimiter` (defaulting to a comma) with each element trimmed. `WhereHas` /
`WhereDoesntHave` are pure existence tests via `hasRelation()` — a non-empty
related collection or a non-null to-one — and ignore the request value entirely.

### ArraySortHandler — one cascading comparator

`ArraySortHandler::apply` honours the single-call ordered-list contract with one
`usort` whose comparator walks the directives in significance order, returning at
the first non-zero comparison:

```php
public function apply(array $sorts, mixed $query): mixed
{
    /** @var list<array{string, bool}> $columns */
    $columns = [];
    foreach ($sorts as $directive) {
        $sort = $directive->sort;
        if (!$sort instanceof SortByField) {
            throw new UnsupportedSort($sort);
        }
        $columns[] = [$sort->column, $directive->descending];
    }
    // …
    \usort($query, static function (mixed $a, mixed $b) use ($columns): int {
        foreach ($columns as [$column, $descending]) {
            $cmp = Accessor::get($a, $column) <=> Accessor::get($b, $column);
            if ($cmp !== 0) {
                return $descending ? -$cmp : $cmp;
            }
        }
        return 0;
    });

    return $query;
}
```

It understands only `SortByField` — the value object every `->sortable()` field
auto-derives — and throws `UnsupportedSort` for anything else, which is exactly
the seam a computed sort hooks into (below).

## Folding the request over the query

An adapter ties the metadata to the request: read the requested `filter[…]`
keys, match each against the resource's declared filters, and hand the matches to
the handler. The worked
[`CriteriaApplier`](../examples/music-catalog/src/Data/CriteriaApplier.php) in the
music-catalog example does exactly this. Filters first, indexed by `key()` so a
requested parameter finds its declared VO:

```php
/** @var array<string, FilterInterface> $declared */
$declared = [];
foreach ($resource->filters() as $filter) {
    $declared[$filter->key()] ??= $filter;   // first declared wins for a shared key
}

foreach ($requested as $key => $value) {
    $filter = $declared[$key] ?? null;
    if ($filter === null) {
        continue;   // an undeclared filter[…] key is silently ignored
    }
    $rows = $filter instanceof WithinRadius
        ? $this->withinRadius($rows, $filter, $value)   // the custom arm
        : $this->delegateFilter($filter, $rows, $value);
}
```

Sorts are gathered into the single ordered list the handler contract demands.
`$resource->allSorts()` returns every declared and `->sortable()`-derived sort,
keyed for lookup; the requested `sort=a,-b` is split into directives, each
carrying its `-`-derived direction:

```php
foreach ($resource->allSorts() as $sort) {
    $allSorts[$sort->key()] = $sort;
}

$directives = [];
foreach ($requested as $entry) {
    $descending = \str_starts_with($entry, '-');
    $key = $descending ? \substr($entry, 1) : $entry;
    if (($sort = $allSorts[$key] ?? null) !== null) {
        $directives[] = new SortDirective($sort, $descending);   // an unknown key is skipped
    }
}

$sorted = $this->sorts->apply($directives, $rows);   // one call, full ordered list
```

A `filter[…]` key or `sort` field that matches no declared metadata is simply
skipped — the library never auto-applies anything, so an undeclared parameter
cannot narrow or reorder the collection. (See
[`FiltersTest`](../examples/music-catalog/tests/FiltersTest.php) and
[`SortsTest`](../examples/music-catalog/tests/SortsTest.php) for these run as
real requests.)

## Extending the vocabulary

Extending the filter or sort vocabulary is one move on both sides of the split:
define a custom value object carrying whatever fields the handler needs, list it
in the resource, and write the handler arm that understands it — **always
together**, because a handler that meets an unrecognised VO throws `Unsupported…`.

The music catalog ships both. A custom **filter**,
[`WithinRadius`](../examples/music-catalog/src/Filter/WithinRadius.php), names the
latitude/longitude columns to read — pure metadata, no execution:

```php
final readonly class WithinRadius implements FilterInterface
{
    public function __construct(
        public string $key,
        public string $latColumn,
        public string $lngColumn,
    ) {}

    public function key(): string { return $this->key; }
}
```

The `CriteriaApplier` carries the matching execution arm — the same move a
Doctrine adapter makes, except it would push a spatial predicate down rather than
filtering an array:

```php
$rows = $filter instanceof WithinRadius
    ? $this->withinRadius($rows, $filter, $value)
    : $this->delegateFilter($filter, $rows, $value);
```

A custom **sort**,
[`TrackCountSort`](../examples/music-catalog/src/Sort/TrackCountSort.php), orders
by a computed `trackCount` that is not a `SortByField`. The reference
`ArraySortHandler` understands only `SortByField`, so the applier executes a
computed-sort **pre-arm** before delegating — the handler never sees it:

```php
foreach ($directives as $directive) {
    if ($directive->sort instanceof TrackCountSort) {
        return $this->sortByTrackCount($rows, $directive->sort, $directive->descending);
    }
}
$sorted = $this->sorts->apply($directives, $rows);   // only SortByField reaches the handler
```

See [filters](filters.md#writing-a-custom-filter) and
[sorts](sorts.md#computed-and-multi-column-sorts) for the value-object side of this in full.

## Writing a handler for a real store

A production adapter implements the same interfaces, narrowing the query to its
own object and matching on the built-in vocabulary:

```php
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\UnsupportedFilter;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;

/** @implements FilterHandlerInterface<QueryBuilder> */
final class DoctrineFilterHandler implements FilterHandlerInterface
{
    public function apply(FilterInterface $filter, mixed $query, mixed $value): mixed
    {
        \assert($query instanceof QueryBuilder);

        return match (true) {
            $filter instanceof Where => $query->andWhere(/* $filter->column, $filter->operator, $value */),
            $filter instanceof WhereIn => $query->andWhere(/* $filter->column IN (…) */),
            // … the rest of the built-in vocabulary …
            default => throw new UnsupportedFilter($filter),
        };
    }
}
```

The reference handlers are the behavioural spec to match: a `like` should be a
case-insensitive substring, a set filter should split on the VO's delimiter, and
an unrecognised VO must throw the typed `Unsupported…` rather than silently
no-op.

### Where the handler runs

The handler is inert until something invokes it for a request. On a bare
framework that something is your [operation handler](operations.md#operationhandlerinterface-the-one-seam):
it resolves the resource, reads `JsonApiRequestInterface::getFiltering()` /
`getSorting()` off the request, matches each requested key against the resource's
declared filters/sorts, and calls your handler — exactly the fold the
[`CriteriaApplier`](../examples/music-catalog/src/Data/CriteriaApplier.php)
performs in [Folding the request over the query](#folding-the-request-over-the-query)
above. The companion Symfony bundle does this wiring for you.

## Constraints follow the same split

Constraints are metadata too, with the same describe/execute separation — but
unlike filters and sorts, the core ships **one** built-in consumer. The
[JSON Schema compiler](schema-validation.md) translates the *structural subset*
of constraints (`Required`, `Min`/`Max`, `Pattern`, formats, …) into a per-type
schema for request validation. That subset is all the core executes itself.

A framework adapter translates the **full** constraint set into its native
validator rules (Symfony Validator, Laravel rules, …) for complete create/update
validation, matching each constraint by its **class**. There is no core
`ConstraintTranslatorInterface` — the translator contract is the adapter's own;
the core's only obligation is to expose the typed constraint VOs off each field.

For a rule the core does not model, define your own `ConstraintInterface` value
object carrying whatever config the rule needs (e.g. a `CouponRedeemable` with a
`$campaign` property) and attach it to a field with `constrain()`:

```php
public function constrain(ConstraintInterface ...$constraints): static
```

A custom constraint is **not round-tripped to JSON Schema** — the schema compiler
skips constraints it doesn't recognise, leaving it for your adapter's translator
to interpret by matching on its class and reading its typed properties. The same
is true of `When`, which gates a constraint set on a closure the JSON Schema
vocabulary cannot express. See [validation](constraints.md) for the constraint
contexts and the structural subset the compiler covers.

## ORM-backed adapters live outside the core

The core ships only the in-memory reference handlers; production handlers for an
ORM or query builder are a separate concern. A dedicated framework bundle —
shipping `FilterHandlerInterface` / `SortHandlerInterface` implementations and a
constraint translator wired into the request lifecycle — belongs outside this
package, so the core stays framework- and storage-agnostic. The companion
Symfony bundle is that adapter: a Doctrine ORM data layer composing exactly these
contracts.

## Next / see also

- [Filters](filters.md) — the filter value objects and their fields.
- [Sorts](sorts.md) — the sort value objects and `->sortable()` derivation.
- [Validation](constraints.md) — the constraint vocabulary and contexts.
- [Schema validation](schema-validation.md) — the one built-in constraint consumer.
- [Resource classes](resources.md) — declaring filters, sorts, and constraints on a type.
- [Middleware](middleware.md) — where `Unsupported…` errors are rendered.
