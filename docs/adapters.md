# Adapters

The library draws a deliberate line between **metadata** and **execution**. The
core ships typed value objects describing intent — [constraints](validation.md)
(`->required()`, `->maxLength()`), [filters](filters.md) (`Where`, `WhereIn`),
[sorts](sorts.md) (`SortByField`) — but it never runs any of them against your
data. Execution lives in **handlers** and **translators** that an adapter
provides: a filter handler turns a `FilterInterface` into a query predicate, a sort handler
turns a `SortInterface` into an ordering, a constraint translator turns a `ConstraintInterface` into
your validator's native rule. This page explains the pattern and how to extend the
vocabulary for your own data layer.

The reason for the split is decoupling: core knows nothing about your storage.
There is deliberately **no generic `Query` interface** — a handler's query
parameter is `mixed`, so a Doctrine handler narrows it to a `QueryBuilder`, an
in-memory handler to an array, a search adapter to its own request object, with
core coupling to none of them.

## Metadata contracts

Each metadata kind is a one-method (or near-empty) interface; the concrete value
objects add public readonly fields a handler reads.

```php
// Resource\Constraint\ConstraintInterface
public function context(): Context;   // create / update / both

// Resource\Filter\FilterInterface
public function key(): string;        // the filter[<key>] this responds to

// Resource\Sort\SortInterface
public function key(): string;        // the sort key (no leading '-')
```

A Resource class declares which it accepts: field [constraints](validation.md) inline on
each field, `filters()` for the filter list, and field `->sortable()` /
[`sorts()`](sorts.md) for the sort list. None of those declarations execute on
their own.

## Handler contracts

Filters and sorts are executed by handlers, both templated on the query type so no
data layer leaks into core:

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
    /** @param TQuery $query @return TQuery */
    public function apply(SortInterface $sort, mixed $query, bool $descending): mixed;
}
```

A handler matches on the concrete metadata type and produces the native query
operation. When it meets a value object it does not recognise it throws —
`Resource\Filter\UnsupportedFilter` or `Resource\Sort\UnsupportedSort`. Both are
**server-configuration errors**, not client errors, so they render as a `500`: a
filter or sort was declared (or routed through) with no handler wired to execute
it. They are `AbstractJsonApiException`s, so the [error-handler
middleware](middleware.md) renders them as JSON:API error documents automatically;
each exposes the offending VO (`$filter` / `$sort`).

## A worked handler

Core ships `Resource\Filter\InMemory\ArrayFilterHandler` and
`Resource\Sort\InMemory\ArraySortHandler` — worked handlers that operate on a PHP
`list<array|object>`. They power the package's own integration tests and serve as
the canonical example for adapter authors. They are **not** a production query
layer: they filter and sort in memory with no indexing. A real adapter pushes the
predicate and the ordering down to its data store.

`ArrayFilterHandler` reads each value object's fields — `Where`'s `operator` and
`deserialize` closure, `WhereIn`'s `delimiter`, and so on — and builds an
`array_filter` predicate, reading model values through a framework-agnostic
accessor (a public property, a getter, or an array key). `ArraySortHandler`
likewise reads `SortByField`'s `column` and applies a stable `usort`, flipping the
comparison for the descending direction. Both fall through to the typed
`Unsupported…` exception for anything they don't recognise.

### Filters

A typical collection handler folds the requested filters and sorts over the query:

```php
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;

$filters = new ArrayFilterHandler();
$sorts = new ArraySortHandler();

$resource = $server->resources()->resourceFor('articles'); // the AbstractResource
$rows = $repository->all();

$requestedFilters = $operation->queryParameters()->filter;
foreach ($resource->filters() as $filter) {
    if (\array_key_exists($filter->key(), $requestedFilters)) {
        $rows = $filters->apply($filter, $rows, $requestedFilters[$filter->key()]);
    }
}

$allowedSorts = [];
foreach ($resource->allSorts() as $sort) {
    $allowedSorts[$sort->key()] = $sort;
}
foreach ($operation->queryParameters()->sort as $field) {
    $descending = \str_starts_with($field, '-');
    $key = \ltrim($field, '-');
    if (isset($allowedSorts[$key])) {
        $rows = $sorts->apply($allowedSorts[$key], $rows, $descending);
    }
}
```

## Writing a custom handler

An adapter for a real data layer implements the same interfaces, narrowing the
query type to its own object:

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

Extending the vocabulary is the same move on both sides: define a custom
[`FilterInterface`](filters.md#writing-a-custom-filter) /
[`SortInterface`](sorts.md#writing-a-custom-sort) value object carrying whatever fields the
handler needs, list it in the Resource class's `filters()` / `sorts()`, and add a branch
for it in your handler. A custom value object and the handler that understands it
are always written together — a handler that meets an unrecognised one throws
`Unsupported…`.

## Constraint translators and custom constraints

Constraints follow the same metadata-plus-translator split, with one core
consumer built in: the [JSON Schema compiler](validation.md) translates the
structural subset of constraints (`Required`, `Min`/`Max`, `Pattern`, formats, …)
into a per-resource schema for request validation. A framework adapter translates
the **full** constraint set into its native validator rules (Symfony Validator,
Laravel rules, …) for complete create/update validation.

For rules the core does not model, define your own `ConstraintInterface` value
object — a typed VO carrying whatever config the rule needs (e.g. a
`CouponRedeemable` with a `$campaign` property) — and attach it to a field with
`constrain()`. It is **not round-tripped to JSON Schema**: the schema compiler
skips constraints it doesn't recognise, leaving it for an adapter to interpret by
matching on its **class**. An adapter's translator reads the constraints off a
field and, recognising the type, applies the rule its typed properties describe —
no opaque `id`/`payload` indirection. The same is true of `When`, which gates a
constraint set on a closure the JSON Schema vocabulary cannot express. See
[Validation](validation.md) for the constraint contexts and the structural subset
the compiler does cover.

## ORM-backed adapters

Core ships only the in-memory reference handlers; production handlers for an ORM
or query builder are a separate concern. A dedicated framework bundle — shipping
`FilterHandlerInterface` / `SortHandlerInterface` implementations and a constraint translator wired
into the request lifecycle — belongs outside this package, so the core stays
framework- and storage-agnostic.

## Related pages

- [Filters](filters.md) — the filter value objects and their fields.
- [Sorts](sorts.md) — the sort value objects and `->sortable()` derivation.
- [Validation](validation.md) — the constraint vocabulary and the JSON Schema compiler.
- [Resource classes](resources.md) — declaring filters, sorts, and constraints on a type.
