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

Every filter implements `Resource\Filter\FilterInterface`, whose two members are
the parameter key and the declared value constraints:

```php
interface FilterInterface
{
    public function key(): string;

    /** @return list<ConstraintInterface> */
    public function constraints(): array; // default []
}
```

Concrete filters add their own `public readonly` fields (`column`, `operator`,
`delimiter`, …) that a handler reads. They are `final readonly` value objects
constructed through `make()`, with immutable `with`-style refinement helpers that
each return a new instance. `constraints()` declares the value constraints a
framework adapter validates a client-supplied value against **before** the filter
reaches the data layer — see [Validating filter values](#validating-filter-values).

### Structured parameters: `DescribesQueryParameter`

By default a filter's OpenAPI `filter[<key>]` parameter is a **scalar** whose value
schema is projected from its `constraints()`. A filter with a **structured** wire
shape — a nested object like `Range`'s `filter[<key>][min]`/`[max]`, or a comma-list —
declares its own OpenAPI parameter envelope by implementing
[`Resource\Filter\DescribesQueryParameter`](../src/Resource/Filter/DescribesQueryParameter.php):

```php
public function describeQueryParameter(Schema $valueSchema): QueryParameterShape
{
    // Wrap the constraint-derived value schema and pair it with an OAS style.
    return new QueryParameterShape(
        Schema::ofType('array')->withItems($valueSchema),
        ParameterStyle::Form,
        false, // explode
    );
}
```

The projector hands you the value schema it already built from your `constraints()`;
you wrap it (into an object's `min`/`max`, an array's `items`, …) and set the OAS
`style`/`explode`. This is what lets a **custom** filter with a non-scalar value
document correctly — the same self-describing seam constraints use for their JSON
Schema keyword ([`ProvidesJsonSchema`](constraints.md#constrain-the-typed-escape-hatch)),
one level up at the parameter.

`Range`/`DateRange` implement it (the `min`/`max` object) and so do the four set
filters, `WhereIn` / `WhereNotIn` / `WhereIdIn` / `WhereIdNotIn` (an array whose OAS
style spells the declared [`delimiter()`](#refinement-helpers) — `form` for a comma,
`pipeDelimited`, `spaceDelimited`; a delimiter OAS cannot spell documents as the single
opaque string the client sends). A filter's container shape is a property of its kind, so
it is projected whether or not the filter declared any constraints.

### Where a filter value's type comes from: `TargetsColumn`

A filter that declares no value constraints has nothing to project a value schema from.
Filters that compare one backing column implement
[`Resource\Filter\TargetsColumn`](../src/Resource/Filter/TargetsColumn.php), and the
OpenAPI projection matches that column against the field inventory of the type being
filtered. Resolve it to exactly one field and the value documents as that field's JSON
type:

```php
Integer::make('views')              // a field …
Where::make('views')                // … and a filter over its column
// → filter[views]: {"type": "integer"}
```

Only the **type** carries over. Not the field's `format`, `enum`, `maxLength` or any
other narrowing: those describe a member in a document body, whereas a `filter[<key>]`
value is an operand your filter's operator compares, and a substring match over an enum
column takes a substring. The fallback also applies **only** where you declared no
constraints at all — declare one and the parameter is exactly what your constraints say,
never a merge.

Where the column resolves to nothing, the value documents as `string`. A query parameter
is a string on the wire whatever the server parses it into, so that is the honest floor;
an empty schema would have said less than the protocol already guarantees. The floor is
what a relationship path (`WhereThrough`), a relationship name (`WhereHas` /
`WhereDoesntHave`), a group fanning one value across several columns (`WhereAll` /
`WhereAny`), a `computed()` field, a column two fields share, a composite (`Map`,
`ArrayList`, `ArrayHash`, `OneOf`) or relation field, and any custom filter naming no
column all land on. Declare a constraint to say something narrower, as
`WhereThrough::make('author.age')->integer()` does.

Nothing ever overwrites a narrower answer. A derived type beats the floor and a declared
constraint beats both.

One relationship path is the exception, because it is resolved rather than guessed: a
`WhereThrough` or `WhereThroughIn` whose path [ends on a relationship](#paths-that-end-on-a-relationship)
compares the related resource's id, so with no declared constraints it takes the related
id's declared format, `integer` for a `numeric()` id and `format: uuid` for a `uuid()` one.
That default is a constraint like any other, so it validates as well as documents.

The presence-only filters (`WhereNull`, `WhereNotNull`, `WhereHas`, `WhereDoesntHave`)
and a [`fixed()`](#fixed-values) filter document as a plain `string` whatever they
target: the server decides the match and discards the value. Their description ends
"Applied when present; value ignored." and their example is always `true`; see
[documenting presence filters](#documenting-presence-filters).

This is documentation, not validation. A derived type never becomes a `400` — only a
declared constraint does (see [Validating filter values](#validating-filter-values)).

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
| `WhereThrough` | `make(string $key, ?string $path = null)` | a relationship path | compares a value reached through a dotted path; `operator()`, `deserializeUsing()`, `constrain(...)` and the value-constraint shortcuts (see [Traversing a relationship path](#traversing-a-relationship-path)) |
| `WhereThroughIn` | `make(string $key, ?string $path = null)` | a relationship path | the value reached through a dotted path is any of a list; always a list, `acceptsList(delimited: false)`, `constrain(...)` and the shortcuts (see [Matching a list through a path](#matching-a-list-through-a-path-wherethroughin)) |

`Where` carries the comparison operator as its third `make()` argument — `=`
(the default), `like`, `starts`, `ends`, `>`, `>=`, `<`, `<=`, `!=` (with `<>` as
an alias), `===`. The reference
[`ArrayFilterHandler`](adapters.md#the-reference-handlers) maps each to a PHP
comparison (`like` is a case-insensitive `stripos`, matching what a SQL
`LIKE '%…%'` gives on common backends; `starts`/`ends` are the case-insensitive
prefix/suffix variants, `LIKE '…%'` / `LIKE '%…'`); a database adapter translates
the same operator strings into its own dialect.

### Convenience filters

Reaching for `Where::make('title', 'title', 'like')` — or wiring the coercion,
the value constraint and the OpenAPI value schema by hand for a numeric or boolean
column — is repetitive and easy to get subtly wrong. The library ships a small
catalogue of **intent-named** filters that name what you mean and bundle the
matching operator, value coercion, value constraint and OpenAPI description into a
single declaration. Each lives in `haddowg\JsonApi\Resource\Filter`; each `make()`
defaults its target column to the filter key.

| Filter | Intent | Presets |
|---|---|---|
| `Contains` | substring match | `like` operator |
| `StartsWith` | prefix match | `starts` operator |
| `EndsWith` | suffix match | `ends` operator |
| `Numeric` | numeric equality | `=`, numeric coercion + `numeric()` constraint |
| `GreaterThan` | strictly greater | `>`, numeric coercion + `numeric()` |
| `GreaterThanOrEqual` | at least | `>=`, numeric coercion + `numeric()` |
| `LessThan` | strictly less | `<`, numeric coercion + `numeric()` |
| `LessThanOrEqual` | at most | `<=`, numeric coercion + `numeric()` |
| `Boolean` | boolean match | `=`, boolean coercion (`asBoolean()`) + `boolean()` |
| `Range` | inclusive `min ≤ x ≤ max` | numeric coercion + `numeric()`; **nested** `{min, max}` value |
| `DateRange` | inclusive date/time range | ISO-8601 → `\DateTimeImmutable` coercion; **nested** `{min, max}` value |

```php
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\GreaterThanOrEqual;
use haddowg\JsonApi\Resource\Filter\Boolean;
use haddowg\JsonApi\Resource\Filter\Range;

public function filters(): array
{
    return [
        Contains::make('title'),                       // filter[title]=android
        GreaterThanOrEqual::make('minYear', 'year'),   // filter[minYear]=1997, compared numerically
        Boolean::make('explicit'),                     // filter[explicit]=true, coerced to a real bool
        Range::make('duration'),                        // filter[duration][min]=120&filter[duration][max]=300
    ];
}
```

The scalar convenience filters (`Contains` through `Boolean`) are thin
`Where` **subclasses**: they preset the operator, a typed value deserializer and
the matching value constraint, so a handler's existing `instanceof Where` arm
dispatches them **unchanged** — no new handler arm needed, and both the reference
`ArrayFilterHandler` and a database adapter run them out of the box. Because they
*are* a `Where`, they compose with its refinement helpers (`default()`, `singular()`,
extra `constrain(...)`), and each carries its own OpenAPI value schema and a
generated description ("Matches values greater than or equal to the given number.",
…) which you can override with `describedAs()`. Any value-carrying filter also takes an
`example(...)`, which becomes the `example` of its `filter[<key>]` parameter in the
[OpenAPI document](openapi.md#authoring-the-schemas-describedas-and-example). Both stay on
the parameter and never reach its value schema.

Their operator is fixed — it *is* the filter's identity — so the third `make()`
argument exists only for signature parity with `Where::make()`, and passing a
different operator (`GreaterThan::make('age', 'age', '<')`) is a loud
`\InvalidArgumentException`, never a silently-ignored value.

The value proposition over a bare `Where` is real: `Numeric`/`GreaterThan`/… coerce
the incoming string to `int`/`float` **before** comparison, so `filter[age]=6`
keeps `18` but not `5` (a lexical string compare would wrongly keep `5`); `Boolean`
coerces `1`/`true`/`on`/`yes` → `true` (and `0`/`false`/`off`/`no`/`''` → `false`)
so a truthy string compares as a real boolean; and each also declares the matching
value constraint, so a mistyped value (`filter[year]=banana`) is a clean `400`
before the filter reaches the data layer (see
[Validating filter values](#validating-filter-values)).

#### Ranges

`Range` and `DateRange` are **genuinely new filter types**, not `Where` presets:
their wire value is **nested** — `?filter[<key>][min]=…&filter[<key>][max]=…`
(Symfony parses this into `['min' => '…', 'max' => '…']`) — and an apply runs *two*
predicates (`min ≤ x` and `x ≤ max`), so a handler needs a dedicated
`instanceof Range` arm (the reference `ArrayFilterHandler` ships one; an OpenAPI
projection advertises the bounds as a `deepObject` parameter).

Either bound may be omitted, so an open-ended range works — `min` alone is a `>=`,
`max` alone a `<=`, and an entirely absent value is a no-op:

```
GET /tracks?filter[duration][min]=120&filter[duration][max]=300   # 120 ≤ duration ≤ 300
GET /tracks?filter[duration][min]=120                             # duration ≥ 120
GET /albums?filter[released][min]=1990-01-01&filter[released][max]=1999-12-31
```

`Range`'s preset deserializer coerces each bound **and the column value** to a
number before comparing (so the range is numeric, not lexical); `DateRange` is a
`Range` whose deserializer coerces each ISO-8601 bound to a `\DateTimeImmutable`
(and the column value likewise), so `published before/after` reads as one key and
the comparison is temporal. Each present bound is validated (numeric for `Range`,
an ISO-8601 shape for `DateRange`), so a malformed bound is a clean `400` rather
than a silent non-match. A `DateRange` bound is read with
[`DateTimeParser`](../src/Resource/DateTimeParser.php), so an impossible date such as
`2026-02-30` stays a string instead of rolling over to 2 March, and the adapter answers
it with the same `400`. Like [`WhereThrough`](#traversing-a-relationship-path)
these are data-layer-specific: core ships the metadata and the reference in-memory
apply, and a database adapter translates each into two push-down `andWhere`
predicates.

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
| `fixed(mixed)` | `Where` (and its convenience subclasses) | pins the compared value so the request value is ignored and the key becomes a presence trigger (see [Fixed values](#fixed-values)) |
| `constrain(...)`, `numeric()`, `integer()`, `uuid(?int)`, `boolean()`, `pattern(string)` | `Where`, `WhereIn`, `WhereNotIn`, `WhereIdIn`, `WhereIdNotIn`, `WhereThrough`, `WhereThroughIn` | declares value constraints validated before the filter runs (see [Validating filter values](#validating-filter-values)) |
| `enum(string $enum)` | `Where`, `WhereIn`, `WhereNotIn`, `WhereThrough`, `WhereThroughIn` | restricts the value, or each member of a list, to a backed enum's backing values (see [Backed enums](#backed-enums)) |
| `deprecated()` | every built-in filter | marks the `filter[<key>]` parameter `deprecated: true` in the [OpenAPI document](openapi.md#deprecation); the filter still applies as before |

```php
use haddowg\JsonApi\Resource\DateTimeParser;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;

Where::make('explicit')->asBoolean();
Where::make('createdAfter', column: 'created_at', operator: '>')
    ->deserializeUsing(static fn(mixed $v): mixed => DateTimeParser::tryParse($v) ?? $v);
WhereIn::make('genres')->delimiter('|');
```

The presence-only filters (`WhereNull`, `WhereNotNull`, `WhereHas`,
`WhereDoesntHave`) carry no refinement helpers other than `describedAs()` and
`deprecated()`: their requested presence *is* their semantics.

### Documenting presence filters

A presence filter applies when its key is sent and ignores the value: the four
presence-only filters, a [`fixed()`](#fixed-values) `Where`, and a
[group](#filter-groups-whereall--whereany) whose children are all fixed. Each takes
`describedAs()`, whose text replaces the generic "Filter the collection by `<key>`." line.
The projector always ends the description with "Applied when present; value ignored." and
always gives the parameter the example `true`, so a fixed `Where`'s own `example()` is not
shown:

```php
WhereNull::make('unbudgeted', 'budget')->describedAs('Campaigns without a budget.');
// description: "Campaigns without a budget. Applied when present; value ignored."
// example: "true"
```

The example is the string `true`, matching the parameter's `string` schema; the value
travels as query text and nothing reads it.

A custom filter is marked deprecated by implementing
`haddowg\JsonApi\Resource\Deprecatable`, whose `isDeprecated()` the projector reads. A
filter built on the `HasValueConstraints` trait has no `deprecated()` of its own, so it
implements that interface the same way.

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
still overrides the default. An empty value is **not** a no-op: under an
`asBoolean()` / `boolean()` filter the empty string coerces through
`FILTER_VALIDATE_BOOLEAN` to boolean `false` (and passes the `boolean()` value
constraint, so it is never a `400`), so `filter[active]=` matches the falsy rows
— this is deliberate and frozen. Anything the client must not be able to undo
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

The OpenAPI document shows a default as the `default` of the `filter[<key>]` parameter's
schema. A list filter's default is shown in both of its parameters' forms, converted the
way [its example is](#declaring-a-list-acceptslist).

A related type's defaults also narrow every to-many relation to that type. A relation
can drop them with `withoutFilterDefaults()`, keeping the filters themselves; see
[dropping the related type's filter defaults](relations.md#dropping-the-related-types-filter-defaults).
The built-ins hand back a copy without the default through `HasRemovableDefault`, so a
handler dispatches it as before. A custom defaulting filter needs that interface too if
a relation is to drop its default. A default the relation drops is left off the
`filter[<key>]` parameters of its related and relationship endpoints.

## Fixed values

`->fixed($value)` pins the compared value on a `Where` (or any convenience
subclass): the request value is **ignored** and the key becomes a **presence
trigger** — sending `filter[<key>]` with *any* value applies `column <operator>
<value>`, and omitting the key does not apply it at all.

```php
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\GreaterThan;

Where::make('status')->fixed('published');   // filter[status]=<anything> → status = 'published'
GreaterThan::make('priority')->fixed(5);      // filter[priority]=<anything> → priority > 5
```

Contrast it with [`default()`](#default-values):

| | `->default($v)` | `->fixed($v)` |
|---|---|---|
| Applies when the key is **absent** | yes (folds `$v` in) | no |
| Applies when the key is **present** | yes, with the client's value | yes, always with `$v` |
| Client can **override** the value | yes — a sent value wins | no — the sent value is ignored |

A default is a convenience the client can override; a fixed value is one it
cannot influence at all. (Anything the client must be *unable to remove* — a
soft-delete exclusion, tenant scoping — still belongs in your data layer, not a
presence-triggered filter the client chooses whether to send.)

`->fixed()` needs **no handler support of its own**: it routes execution through
the existing value-deserializer seam (the compared value becomes a constant), so
the built-in `Where` arm — in the reference `ArrayFilterHandler` and in a Doctrine
or Eloquent adapter — runs it unchanged. Because the request value carries no
meaning, any [declared value constraints](#validating-filter-values) are dropped
(there is nothing client-supplied to validate), and the OpenAPI generator
documents the parameter honestly as server-applied: its presence applies the
filter, its value is ignored. See
[documenting presence filters](#documenting-presence-filters).

## Validating filter values

A filter is metadata only: nothing about `Where::make('age', 'age')` says the
value must be a number. Without a declared constraint a mistyped value
(`GET /tracks?filter[duration]=banana` against an integer column) flows straight
to the data layer and gets the adapter's unhelpful default: the in-memory handler
and a loosely-typed database (sqlite) simply never match, while a strict driver
such as Postgres raises a PDO type error (a `500`). Declaring a **value constraint**
turns that into a clean `400`, validated *before* the filter reaches the data layer.

Declare constraints on a value-carrying filter (`Where`, `WhereIn`, `WhereNotIn`,
`WhereIdIn`, `WhereIdNotIn`) with `constrain()` or the type shortcuts — immutable
withers that append the matching core constraint, mirroring the `Id` field's
`uuid()` / `numeric()` / `pattern()`:

```php
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Constraint\Min;

public function filters(): array
{
    return [
        Where::make('year')->integer(),                 // ^-?[0-9]+$
        Where::make('rating')->numeric(),               // integer or decimal
        Where::make('active')->boolean(),               // 1/0, true/false, on/off, yes/no, ''
        Where::make('isrc')->pattern('^[A-Z]{2}.{10}$'),// any ECMA-262 regex
        WhereIdIn::make()->uuid(4),                      // a UUIDv4 id set
        Where::make('listens')->constrain(new Min(0)),   // any core ConstraintInterface
    ];
}
```

| Shortcut | Appends | Wire form it accepts |
|---|---|---|
| `numeric()` | `Pattern('^-?[0-9]+(?:\.[0-9]+)?$')` | an integer or decimal |
| `integer()` | `Pattern('^-?[0-9]+$')` | an integer |
| `uuid(?int $version = null)` | `UuidFormat($version)` | a UUID (optionally a specific version) |
| `boolean()` | `Pattern('^\s*(?i:true\|false\|1\|0\|on\|off\|yes\|no)\s*$\|^\s*$')` | the `FILTER_VALIDATE_BOOLEAN` set: `1`/`0`, `true`/`false`, `on`/`off`, `yes`/`no`, or the empty string — case-insensitive, surrounding whitespace tolerated |
| `pattern(string $regex)` | `Pattern($regex)` | a value matching the ECMA-262 regex |
| `constrain(ConstraintInterface ...)` | the given constraints | as the constraint describes |

`constrain()` and the shortcuts reuse the same
[constraint vocabulary](constraints.md) as fields, so any
`Resource\Constraint\ConstraintInterface` (`Min`, `Max`, `In`, …) works too.

### Backed enums

`enum()` takes a backed enum's class, as a field builder's `enum()` does, and appends an
`In` over the backing values of its cases that keeps the enum's class:

```php
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;

public function filters(): array
{
    return [
        Where::make('state')->enum(RunState::class),                    // filter[state]=running
        WhereIn::make('states', 'state')->enum(RunState::class)->acceptsList(), // each member checked
    ];
}
```

It is on `Where`, `WhereIn`, `WhereNotIn`, `WhereThrough` and `WhereThroughIn`, so a
filter a [family](#filter-families-keys-matched-by-prefix) resolves to can declare it
too. A list filter checks each member against it. It refuses a pure enum when you
declare it, since its cases have no value a request could carry.

The filter still compares the backing value, never the case, which Doctrine and
Eloquent both bind against an enum column, so `enum()` only validates and documents. In
the OpenAPI document the parameter's value, or each item of a list parameter, is a `$ref`
to the enum's [component](openapi.md#backed-enums-x-enum-varnames--x-enum-descriptions)
with the enum's backing type beside it, the same one a field declaring the enum references, so a client generator types the
filter and the field with one enum. The `filter` parameter of a family keeps describing
its members as strings, since its members are resolved per request.

Constraints are **metadata only** — core never executes them. A framework adapter
(the Symfony bundle) translates them to its native validator and checks the
client-supplied value, throwing `Exception\FilterValueInvalid` on a violation:

```json
{
  "errors": [
    {
      "status": "400",
      "code": "FILTER_VALUE_INVALID",
      "title": "Filter value is invalid",
      "detail": "This value should be of type integer.",
      "source": { "parameter": "filter[year]" }
    }
  ]
}
```

It is a **`400`** — a bad query *parameter*, located by `source.parameter`, not a
`422` (which is reserved for document semantic errors located by `source.pointer`).
One error is rendered per violation. Only a **client-supplied** value is validated;
a filter's author-set `default()` is trusted and never checked. A filter that
declares no constraints behaves exactly as before. Validation is provider-agnostic
— it runs on the value, before any handler — so both the in-memory and database
adapters get the same clean `400` instead of the silent non-match (or, on a strict
driver, the downstream `500`) an unvalidated value would yield. The check is
opt-in on the adapter's validator integration: with no validator installed a filter
that declares constraints behaves as if it had none (see the bundle's filter docs).

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

## Traversing a relationship path

`WhereThrough` filters on a value reached by walking a **dotted relationship
path** — `artist.name` keeps a row whose artist's name matches, `comments.body`
keeps a row that has *some* comment whose body matches, `author.company.name`
chains the hops. Every intermediate segment is a relationship (to-one or to-many
— both read identically as "there exists a … whose …") and the final segment is
the compared attribute. It is an **EXISTS-ANY semi-join**: a database adapter
interprets the path as a correlated `EXISTS` subquery, never a fetch-join, so it
neither hydrates the relation nor multiplies rows. The
[`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php)
exposes albums by their artist's name:

```php
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereThrough;

public function filters(): array
{
    return [
        WhereHas::make('tracks'),
        // EXISTS-ANY over album->artist->name; no fetch-join (the Doctrine
        // reference renders a correlated EXISTS).
        WhereThrough::make('artist.name'),
    ];
}
```

```
GET /albums?filter[artist.name]=Radiohead   →  one album: id 1 "OK Computer"
GET /albums?filter[artist.name]=Portishead  →  one album: id 2 "Dummy"
```

The single-argument form uses the dotted path as **both the wire key and the
traversal path** — `WhereThrough::make('artist.name')` responds to
`filter[artist.name]`. Supply a second argument to decouple them, exposing a
clean wire key over a deeper path:

```php
use haddowg\JsonApi\Resource\Filter\WhereThrough;

// filter[topArtist]=Radiohead, traversing album->artist->name
WhereThrough::make('topArtist', 'artist.name');
```

Both positional slots are taken by the key and the path, so the comparison
operator is the fluent `operator()` setter rather than a `make()` argument
(default `=`). It draws the same vocabulary as [`Where`](#the-built-in-catalogue)
— `=`, `!=` (alias `<>`), `>`, `>=`, `<`, `<=`, `like` — applied at the leaf
segment:

```php
use haddowg\JsonApi\Resource\Filter\WhereThrough;

WhereThrough::make('artist.foundedYear')->operator('>=');
```

`WhereThrough` carries [value constraints](#validating-filter-values) the same
way the column filters do — `constrain(...)` or the type shortcuts
(`integer()`, `numeric()`, `uuid()`, `boolean()`, `pattern()`) append the matching
constraint so a mistyped value is a clean `400` before the filter reaches the data
layer — and a `deserializeUsing()` transformer applied before comparison, exactly
as on `Where`.

`WhereHas` and `WhereDoesntHave` are the **degenerate length-1 case** of this same
traversal: a single relationship segment with no leaf comparison, where presence
alone (a non-empty related collection or a non-null to-one) decides the match. The
reference `ArrayFilterHandler` folds both onto the one `WhereThrough` traversal
walk — `WhereHas` is "the path reaches at least one present value", and a Doctrine
adapter renders all three as the same `EXISTS` shape. The
[`FiltersTest`](../examples/music-catalog/tests/FiltersTest.php) witnesses the
`artist.name` traversal narrowing albums to each artist's catalogue.

## Matching a list through a path: `WhereThroughIn`

`WhereThroughIn` is `WhereThrough` with `WhereIn` at the leaf: the row matches when the
value reached through the path is any of the requested values. It takes the same key and
path rules, so `WhereThroughIn::make('templates')` answers `filter[templates]` and
`WhereThroughIn::make('advertiser', 'campaign.advertiser')` answers `filter[advertiser]`:

```php
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;

public function filters(): array
{
    return [
        WhereThroughIn::make('advertiser', 'campaign.advertiser'),
        WhereThroughIn::make('template', 'templates')->describedAs('Leads using any of these templates.'),
        WhereThroughIn::make('campaignName', 'campaign.name')->acceptsList(delimited: false),
    ];
}
```

```
GET /leads?filter[advertiser]=4,7
GET /leads?filter[advertiser][]=4&filter[advertiser][]=7
```

Its value is always a [list](#declaring-a-list-acceptslist), read exactly as a `WhereIn`
declared with `acceptsList()` reads one, so there is no `acceptsList()` call to make. Both
forms work and both are documented, a keyed member is a `400`, and
`acceptsList(delimited: false)` keeps a comma inside a value. Every member is validated
against the declared constraints on its own, with the same `400` `FILTER_VALUE_INVALID` a
`WhereIn` gives. An empty list matches nothing.

A data layer runs it as one correlated `EXISTS` whose leaf is an `IN` over the list, never
one `EXISTS` per value. It carries `describedAs()`, `example()` and `deprecated()` like the
other filters, but no `delimiter()`, `default()` or `singular()`.

## Paths that end on a relationship

A `WhereThrough` or `WhereThroughIn` path may end on a relationship instead of a field:
`campaign.advertiser` ends on the campaign's `advertiser`, and `templates` on the type's own
`templates`. The filter then compares the related resource's **id**:

```php
WhereThroughIn::make('advertiser', 'campaign.advertiser'); // filter[advertiser]=4,7
WhereThrough::make('template', 'templates');               // filter[template]=9f3c…
```

The client sends wire ids, the ones it reads in `data.id`. Each is decoded through the
related type's [id encoder](ids.md#encoding-when-the-wire-id-differs-from-the-storage-key) before the comparison, so a type whose ids are
encoded filters by the same id it serves. An id the encoder cannot decode is a `400`
`FILTER_VALUE_INVALID` at the parameter, never a failed query. With no declared
constraints the values take the related id's format, so a `numeric()` id validates and
documents as an integer and a `uuid()` id as a UUID. Declare constraints to say something
else.

The leaf must be monomorphic. A path ending on a `MorphTo` or `MorphToMany` has no single
id to compare and throws a `\LogicException` when the path is resolved. A `WhereThrough`
on a relationship compares only by equality (`=`, `!=` and their aliases) and takes no
`deserializeUsing()`, since the encoder decides what the id means; either one throws the
same way.

Telling a relationship from a field needs the relation graph, which a filter declaration
does not have, so the data layer resolves each path against the type the filter runs
against with a [`ThroughPathResolver`](../src/Resource/Filter/ThroughPathResolver.php).
Each segment matches a declared relation by its storage name, its `column()`, which is the
name the data layer traverses. When every segment matches, the path ends on a
relationship. When one does not, the path ends on a field and behaves exactly as it always
has. The OpenAPI projection resolves the same way, and adapters resolve after matching the
requested keys and may resolve the declared filters at warm-up to fail fast:

```php
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;

$paths = ThroughPathResolver::forResolver($server);
$filter = $paths->resolve($directive->filter, $resource->allFields());
```

The resolved filter tells the data layer everything it needs.
`$filter->resolvedPath()` returns a
[`ThroughPath`](../src/Resource/Filter/ThroughPath.php) with the `segments`,
`endsOnRelation()`, the `relatedType` and the related id field.
`WhereThroughIn::storageValues()` and `WhereThrough::storageValue()` return the decoded
storage keys to compare, and `constraints()` returns what each member validates against,
the related id's format included. A group's children are resolved with it.

## List and set values

A set filter (`WhereIn`, `WhereNotIn`, the id variants) treats its incoming
value as a **list**: either an already-array value
(`filter[genres][]=a&filter[genres][]=b`) or a delimited string
(`filter[genres]=a,b`, split on the configured `delimiter()`). The split happens
in the handler: the reference `ArrayFilterHandler` consults `$filter->delimiter`
(defaulting to `,`) and treats an array value as already-split.

### Declaring a list: `acceptsList()`

`acceptsList()` declares the value a list in a way adapters, validators and the
OpenAPI document all read:

```php
WhereIn::make('genres')->acceptsList();                   // filter[genres][]=a&filter[genres][]=b, or filter[genres]=a,b
WhereIn::make('columns')->acceptsList(delimited: false);  // filter[columns][]=a,b only: the comma stays in the value
```

A declared list is read through [`ListValue::values()`](../src/Resource/Filter/ListValue.php),
which [`FilterMatcher::directives()`](#matching-requested-filters-filtermatcher) calls for
you:

- The array form is always accepted. Its members must be single values, and a member
  keyed by name (`filter[genres][x]=a`) is a `400` `FilterValueInvalid`.
- A delimited list also accepts one string, split on the filter's `delimiter()` and
  trimmed.
- With `delimited: false` a single string is a `400` `FilterValueInvalid` rather than
  split, so `filter[columns][]=last,first` asks for the one column named `last,first`.

Adapters validate each member against the filter's value constraints on its own. The
OpenAPI document adds a `filter[<key>][]` parameter for the array form, see
[OpenAPI generation](openapi.md#list-filters-x-filter-list). An `example()` or
`default()` shows the same members in both parameters, whichever form you declare it in:
`'red,blue'` and `['red', 'blue']` both become `["red", "blue"]` on the array form, and on
a delimited `filter[<key>]` whose delimiter OAS can't spell, where the schema is a string,
an array is joined with the delimiter. Without `acceptsList()`
a set filter keeps reading both forms leniently and documents only the delimited one,
exactly as before.

A custom filter declares a list by implementing
[`AcceptsList`](../src/Resource/Filter/AcceptsList.php), whose `listValue()` returns
`ListValue::delimited()`, `ListValue::arrayOnly()` or `null`.

## Filter families: keys matched by prefix

Some filter keys are data rather than vocabulary, the filter twin of a
[sort family](sorts.md#sort-families-keys-matched-by-prefix): a campaign's placeholder
values, custom fields, EAV attributes, members of a JSON column. A
[`FilterFamily`](../src/Resource/Filter/FilterFamily.php) answers every key that starts
with a prefix and a dot, and resolves the rest of the key to a concrete filter:

```php
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Filter\FilterFamily;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\WhereIn;

public function filters(): array
{
    return [
        FilterFamily::make('value', fn(string $suffix, JsonApiRequestInterface $request): ?FilterInterface
            => $this->placeholders->exists($suffix)
                ? WhereIn::make('value.' . $suffix, 'placeholders.' . $suffix)->acceptsList()->build()
                : null)
            ->acceptsList(),
    ];
}
```

`?filter[value.cap]=a,b` calls the resolver with `cap`. The rules match sort families:

- The suffix is everything after `<prefix>.`, dots included. The bare prefix never
  matches, so `?filter[value]=x` is unrecognized unless an exact filter is keyed
  `value`, and the two can sit side by side.
- Returning `null` rejects the key with the usual `400` `FilterParamUnrecognized`.
- The filter you return must be keyed by the full requested key and accept the list
  the family declares: none, a delimited one or an array-only one. Anything else is a
  `\LogicException` naming the family, since the OpenAPI document describes the
  family's value and the data layer reads the resolved filter's.
- An exact key always wins over a family. When two families' prefixes both fit, the
  longer prefix decides, and its `null` is final.

By default any non-empty suffix is accepted. `withSuffixPattern()` narrows it to a
regular-expression fragment the whole suffix must match, checked before the resolver
runs and projected into the [OpenAPI document](openapi.md#filter-families-x-filter-families).
`deprecated()` marks the family deprecated there.

`describedAs()` says what the family's keys filter by, and `example()` shows one key the
family answers with the value sent with it. Both go in the family's `x-filter-families`
entry. The description also becomes a line of the shared `filter` parameter's description,
and the example joins the parameter's example:

```php
FilterFamily::make('value', $resolver)
    ->withSuffixPattern('[a-z_]+')
    ->describedAs('Matches a placeholder value.')
    ->example('cap', '10');            // filter[value.cap]=10, written as {"value.cap": "10"}
```

The example's suffix must match the family's suffix pattern, or `example()` throws.

A family is never handed to a filter handler. Matching replaces it with the filter its
resolver returns, so families work wherever a filter key does: the primary `filter`, a
relation's filters, the [Relationship Queries](profiles/relationship-queries.md)
profile's `relatedQuery[<path>][filter]`, and a
[collection action's filters](#filters-on-a-collection-action). A family has no default
value. To write your own, implement
[`FilterFamilyInterface`](../src/Resource/Filter/FilterFamilyInterface.php).

### Matching requested filters: `FilterMatcher`

[`FilterMatcher`](../src/Resource/Filter/FilterMatcher.php) is the one place a requested
key meets the declared vocabulary, shared with
[`SortMatcher`](sorts.md#reading-the-requested-sorts):

- `match($filters, $key, $request)` returns the filter a key selects, resolving a
  family, or `null`.
- `directives($requested, $filters, $request)` returns one `FilterDirective` per
  requested key, in request order, carrying the matched filter and its value, read as a
  list when the filter accepts one. An unknown key is a `400` `FilterParamUnrecognized`.
- `merge(...$vocabularies)` merges vocabularies, a later filter replacing an earlier
  one with the same key while a family and an exact filter of the same key both stay.

## Filter groups: `WhereAll` / `WhereAny`

By default distinct filters combine with an implicit **AND** across separate
`filter[<key>]` keys. When you need **OR across columns**, or a single named
condition that bundles several comparisons, compose a **filter group**:
`WhereAll` (AND) and `WhereAny` (OR) are value objects carrying a `key()` and a
`list<FilterInterface>` of children. The group is composed **server-side** by the
resource author — the client picks *whether* to send `filter[<key>]`, never how
the boolean algebra is assembled.

Two properties make groups expressive:

- The group **passes its own request value to every child**. So a group of
  value-carrying children fans one value across columns.
- A child's own `key()` is **ignored as a request parameter** (only the group's
  key is a `filter[...]`), but still drives the child's **column** — so
  `Contains::make('name')` filters column `name` whatever the group key is.

### Fan-out search

`WhereAny` over several `Contains` children is a multi-column search — one value,
many columns:

```php
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\Contains;

public function filters(): array
{
    return [
        WhereAny::make('q', Contains::make('name'), Contains::make('email'))
            ->describedAs('Search by name or email.'),
    ];
}
```

```
GET /users?filter[q]=foo   →  name LIKE '%foo%' OR email LIKE '%foo%'
```

A fanning group declares its **shared value's** constraints exactly like a
`Where` (`->numeric()`, `->pattern(...)`, `->constrain(...)`), and the OpenAPI
generator projects it as a single scalar `filter[q]` value parameter.

That one parameter is the only place the group appears in the document, so its
description and example belong on the group. A child that declares `describedAs()` or
`example()` of its own fails when the group is built, which a host does at warm-up, with a
message pointing at the group. A convenience filter's preset description, such as
`Contains`'s, doesn't count.

### Canned toggle

Combine `->fixed()` children and the group becomes a **canned toggle** — its
presence applies a fixed, multi-condition predicate and the request value is
ignored:

```php
use haddowg\JsonApi\Resource\Filter\WhereAll;
use haddowg\JsonApi\Resource\Filter\GreaterThan;
use haddowg\JsonApi\Resource\Filter\Boolean;

WhereAll::make('urgent',
    GreaterThan::make('priority')->fixed(5),
    Boolean::make('flagged')->fixed(true),
);
```

```
GET /tickets?filter[urgent]=1   →  priority > 5 AND flagged = true
```

Because every child is [fixed](#fixed-values), the group is presence-triggered:
the OpenAPI generator documents `filter[urgent]` as
[a presence filter](#documenting-presence-filters), and a group whose children all fix
their value declares no value constraints of its own.

### Nesting

Groups nest arbitrarily — a group child is itself a `FilterInterface`, so it
re-enters the same dispatch — letting the author compose `(A AND (B OR C))`:

```php
use haddowg\JsonApi\Resource\Filter\WhereAll;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\Boolean;
use haddowg\JsonApi\Resource\Filter\GreaterThan;

WhereAll::make('search',
    Contains::make('name'),                       // fans the request value
    WhereAny::make('inner',
        Boolean::make('flagged')->fixed(true),    // ignores it
        GreaterThan::make('priority')->fixed(100),
    ),
);
```

```
GET /users?filter[search]=bob
    →  name LIKE '%bob%' AND (flagged = true OR priority > 100)
```

This stays owner-vetted: the client cannot assemble arbitrary boolean algebra
(no client-driven `filter[and]`/`[or]`), so it does not widen the allow-list the
group defines.

Each provider runs a group through one recursive handler arm that combines its
children with `AND`/`OR` — the reference `ArrayFilterHandler` ships one, and the
Doctrine and Eloquent adapters each add their own (`andX()`/`orX()`, nested
`where(fn ($q) => …)`). A child the handler doesn't recognise resolves through the
same [custom-filter](#writing-a-custom-filter) fallthrough it would at the top level.

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

An *undeclared* filter key (one no resource lists) is a different case, and core
leaves it to the handler. Core never applies a filter itself, so it never sees the
key. A handler that skips it, as the example app's below does, answers
`GET /artists?filter[withinRadius]=51.5` against a resource that doesn't declare
`withinRadius` with the full collection, unnarrowed. A handler that rejects it throws
[`FilterParamUnrecognized`](errors-and-exceptions.md), a `400`
`FILTERING_UNRECOGNIZED` naming the key. The Symfony bundle and the Laravel adapter
both reject it.

That choice is **key-level**: an unrecognized key *inside* the `filter` family. The
`filter` **family** itself is always recognized, so it never trips the
[strict query-parameter validation](content-negotiation.md#strict-query-parameter-validation-on-by-default)
that `Server` runs by default: an unrecognized query-parameter *family* (a
misspelled `?fitler[...]`, an unregistered custom parameter) is a `400`
`QueryParamUnrecognized` whatever the handler does with keys.

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
        continue; // this handler skips an undeclared key
    }
    // … dispatch $filter + $value to the handler …
}
```

## Filters on a collection action

A collection-scope custom action can take the filters its type declares, all of them or a
named subset, so `POST /articles/-actions/archive?filter[status]=draft` narrows the rows the
action works on with the same `filter[status]` a collection fetch accepts. The action's
metadata opts in through `DeclaresActionFilters`, and `ActionDeclarations::filters()`
returns the filters it takes. The adapter treats them as it treats a collection fetch's: it
validates each value against the filter's constraints, applies each requested filter through
the same handler, and answers an undeclared key or an invalid value with `400`. See
[OpenAPI generation](openapi.md#filters-on-a-collection-action) for how the parameters are
documented.

## Next / See also

- [Adapters](adapters.md) — the handler side: applying filters against your data
  layer, the reference `ArrayFilterHandler`, and a worked custom-filter arm.
- [Sorts](sorts.md) — the same metadata/handler split for the `sort` parameter.
- [Pagination](pagination.md) — windowing a filtered, sorted collection.
- [Resource classes](resources.md) — declaring `filters()` on a resource type.
- [Validation](constraints.md) — the constraint metadata this pattern mirrors.
- [OpenAPI generation](openapi.md) — how a filter's value schema and description
  (including a `Range`/`DateRange` `deepObject` parameter) are projected.
- The **Symfony bundle**'s data-layer docs cover the Doctrine execution side —
  how each built-in and convenience filter (including the `EXISTS` relationship
  filters and the two-predicate ranges) translates to a `QueryBuilder` predicate.
