# Validation constraints

A [field](fields.md) carries validation **metadata** — `->required()`,
`->maxLength(200)`, `->in([...])`, `->before(...)`. This page is the reference for
that vocabulary: the constraint model, the create/update context that scopes every
rule, the full list of constraints as a table, and the few rules whose behaviour
needs a worked example. By the end you can declare any built-in rule, scope it to
POST or PATCH, and reach for `constrain()` when the built-ins don't cover a case.

## Constraints are metadata core never executes

Every constraint is a `final readonly` value object implementing
[`Resource\Constraint\ConstraintInterface`](../src/Resource/Constraint/ConstraintInterface.php),
whose single method `context(): Context` reports when it applies. You rarely
construct one directly — the fluent field builders append them for you, so
`Str::make('title')->required()->maxLength(200)` adds a `Required` and a
`MaxLength`. Core stores this metadata and exposes it; it does **not** run it.
Two consumers read it instead:

- the **`SchemaCompiler`** ([validation](schema-validation.md#per-resource-schemas-schemacompiler))
  compiles the *structural* subset into a per-resource JSON Schema, so the
  document-validation layer tightens request bodies for free;
- a **framework adapter** translates the *full* set into its native validator and
  executes value-level validation, rendering a failure as `422` with a
  `source.pointer`.

That split is deliberate: core defines the vocabulary, an adapter runs it. The
boundary statement appears once at the [end of this page](#the-core-boundary).

## The create/update context

JSON:API treats POST (create) and PATCH (update) differently — a PATCH is a
partial update, so absence means "no change". Every constraint therefore carries a
[`Context`](../src/Resource/Constraint/Context.php) declaring whether it applies on
create, update, or both:

```php
final readonly class Context
{
    public function __construct(public bool $onCreate = true, public bool $onUpdate = true) {}

    public static function always(): self;      // both (the default)
    public static function onlyCreate(): self;
    public static function onlyUpdate(): self;

    public function appliesTo(bool $creating): bool;
}
```

Constraints default to `always()`. Scope the constraints a field adds with the
per-field `onCreate()` / `onUpdate()` builders — each runs a closure and stamps
**every constraint appended inside it** with that context:

```php
Str::make('handle')->onCreate(static function (Str $field): void {
    $field->required()->slug();   // both stamped onlyCreate()
});
```

Each built-in helper reads the field's current context when it runs, so it
re-stamps the constraint it adds. The one exception is **`constrain()`**: it
attaches a constraint you built yourself and does **not** re-stamp it, so a custom
constraint carries whatever `Context` you constructed it with.

For the common presence case there are direct shortcuts — `requiredOnCreate()` and
`requiredOnUpdate()` — that need no closure.

### `Required` and `Nullable` semantics

`Required` means **present and non-empty**, but its strictness follows the context:

- On **create** (POST) the field must be present and non-empty.
- On **update** (PATCH) absence means "no change", so a plain `->required()` does
  **not** force the member to appear; only an explicitly-supplied empty value
  fails.

If you need a member present on a PATCH too, scope a `Required` to update with
`->requiredOnUpdate()`; `->requiredOnCreate()` is the create-only form, and
`->required()` applies in both contexts (with the per-context strictness above).

`Nullable` widens the allowed value to include an explicit `null` (a nullable
`type` union in JSON Schema). It is **independent of presence** — a field can be
required *and* nullable (must be supplied, but `null` is an acceptable value).

## The constraint vocabulary

Every rule below is a value object under
[`Resource\Constraint\`](../src/Resource/Constraint/). The **Emitted by** column
is the fluent field method that adds it (see [fields](fields.md) for which field
type carries which method); construct the VO directly only inside `each()`,
`sequentially()`, `atLeastOneOf()`, or `constrain()`.

| Constraint | Applies to | Emitted by | Options / notes |
|---|---|---|---|
| `Required` | any field | `->required()` / `->requiredOnCreate()` / `->requiredOnUpdate()` | present + non-empty (context-sensitive on PATCH) |
| `Nullable` | any field | `->nullable()` | explicit `null` allowed; independent of presence |
| `Min` / `Max` | `Integer`, `Decimal` | `->min($v)` / `->max($v)` | inclusive numeric bound |
| `ExclusiveMin` / `ExclusiveMax` | `Integer`, `Decimal` | `->exclusiveMin($v)` / `->exclusiveMax($v)` | exclusive numeric bound |
| `MultipleOf` | `Integer`, `Decimal` | `->multipleOf($v)` | value must be a multiple of `$v` |
| `MinLength` / `MaxLength` | `Str` (+ subtypes) | `->minLength($n)` / `->maxLength($n)` | string length bounds |
| `Pattern` | `Str`, `Id` | `->pattern($regex)` | regex match (no delimiters) |
| `EmailFormat` | `Str`, `Email` | `->email($strict = false)` / `Email`'s `->strict()` | `strict: bool` (RFC vs HTML5) |
| `UrlFormat` | `Str`, `Url` | `->url($allowedSchemes = [])` | `list<string> $allowedSchemes` |
| `UuidFormat` | `Str`, `Uuid` | `->uuid($version = null)` | `?int $version` |
| `IpFormat` | `Str`, `Ip` | `->ip($version = null)` / `Ip`'s `->v4()` / `->v6()` | `?int $version` (4 or 6; null = both) |
| `SlugFormat` | `Str`, `Slug` | `->slug($regex = null)` | `string $regex` (defaults to a kebab-case pattern) |
| `MinItems` / `MaxItems` | `ArrayList`, `HasMany` | `->minItems($n)` / `->maxItems($n)` | array length bounds |
| `UniqueItems` | `ArrayList` | `->uniqueItems()` | no duplicate items |
| `MinProperties` / `MaxProperties` | `ArrayHash` | `->minProperties($n)` / `->maxProperties($n)` | object key-count bounds |
| `In` / `NotIn` | any field | `->in($values)` / `->notIn($values)` | `list<mixed>` allow/deny set |
| `Each` | `ArrayList` | `->each(...$constraints)` | applies the wrapped constraints to every item |
| `Before` / `After` | `Date`, `DateTime`, `Time` | `->before($bound)` / `->after($bound)` | `\DateTimeInterface` or `\Closure(): \DateTimeInterface` |
| `Between` | `Date`, `DateTime`, `Time` | `->between($min, $max)` | inclusive range; each bound fixed or closure |
| `Sequentially` | any field | `->sequentially(...$constraints)` | apply in order, stop at first failure |
| `AtLeastOneOf` | any field | `->atLeastOneOf(...$alternatives)` | pass if any one alternative holds |
| `When` | any field | `->when($condition, $builder)` | conditional set gated by a closure |
| `CompareField` | any field | `->compareWith($field, $operator)` | cross-field comparison; see the `Comparison` enum |
| `RelationshipType` | relations | `->type($t)` / `->types($t)` (see [relations](relations.md)) | constrains linkage `type` member(s) |

`RelationshipType` is the one relation-facing constraint — it is not an attribute
rule. It pins a relationship's resource-identifier `type` member(s) to an allowed
set; for a polymorphic relationship the list carries every permitted inverse type.
It is added for you by a relation field's `type()` / `types()` builders.

### The `Comparison` enum

`CompareField` (and `->compareWith()`) takes a
[`Comparison`](../src/Resource/Constraint/Comparison.php) case. The operator reads
`<this field> <operator> <other field>`:

| Case | Symbol |
|---|---|
| `EqualTo` | `=` |
| `NotEqualTo` | `!=` |
| `GreaterThan` | `>` |
| `GreaterThanOrEqual` | `>=` |
| `LessThan` | `<` |
| `LessThanOrEqual` | `<=` |

## Worked: closure date bounds

A date bound is either a fixed `\DateTimeInterface` or a closure resolved at
validation time. [`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php)
forbids a future release date with a closure bound:

```php
DateTime::make('releasedAt')
    ->before(static fn(): \DateTimeImmutable => new \DateTimeImmutable())
    ->useTimezone('UTC')
    ->sortable(),
```

The two bound kinds round-trip differently. A **fixed** bound is schema-visible —
the compiler emits a `formatMinimum` / `formatMaximum` keyword for it. A **closure**
bound is opaque PHP, so it **does not** round-trip to JSON Schema; only an adapter
that executes validation evaluates the closure (here, against "now" for each
request). That is the right trade: a relative bound like "no future dates" can't be
frozen into a static schema, so it stays adapter-only.

## Worked: composition

`Sequentially` and `AtLeastOneOf` build compound rules from the same vocabulary.
The difference: `Sequentially` requires **all** wrapped constraints (in order,
stopping at the first failure) and therefore round-trips to JSON Schema by merging
them into the field's own schema; `AtLeastOneOf` requires **any one** alternative
(an `anyOf`). [`UserResource`](../examples/music-catalog/src/Resource/UserResource.php)
demands a password fragment that is either long enough *or* contains a digit:

```php
Str::make('passwordConfirm')
    ->atLeastOneOf(
        new MinLength(8),
        new \haddowg\JsonApi\Resource\Constraint\Pattern('^.*[0-9].*$'),
    )
```

Each alternative is itself a single constraint. When one alternative needs to be
**several rules at once** (say, "a valid URL" *and* "at least 10 characters"),
nest those rules in a `Sequentially` so the whole group counts as one alternative:

```php
->atLeastOneOf(
    new \haddowg\JsonApi\Resource\Constraint\UrlFormat(),
    new \haddowg\JsonApi\Resource\Constraint\Sequentially([
        new \haddowg\JsonApi\Resource\Constraint\MinLength(10),
        new \haddowg\JsonApi\Resource\Constraint\SlugFormat(),
    ]),
)
```

## Worked: `CompareField` direction

`compareWith()` puts **this field on the left**. In
[`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php) the
availability window reads `availableUntil > availableFrom`:

```php
Date::make('availableUntil')
    ->nullable()
    ->compareWith('availableFrom', Comparison::GreaterThan),
```

A non-directional comparison drops out as a special case — `UserResource` asserts
`passwordConfirm = password` with `Comparison::EqualTo`, where left/right order
doesn't matter.

## Worked: the `when()` fluent form

`when($condition, $builder)` applies a constraint set only when the condition
closure returns true for the value under validation. The builder closure appends
constraints to the field as usual; `when()` captures them into a single `When`.
[`UserResource`](../examples/music-catalog/src/Resource/UserResource.php) requires
a minimum length only when the confirmation field is actually supplied:

```php
->when(
    static fn(mixed $value): bool => $value !== null && $value !== '',
    static function (Str $field): void {
        $field->minLength(8);
    },
)
```

The condition is opaque PHP, so `When` never round-trips to JSON Schema; an adapter
evaluates it. Internally, `when()` swaps in a fresh capture buffer, runs the
builder, then folds the collected constraints into one `When` carrying the field's
current context — so anything you append inside the builder (here `minLength(8)`)
is captured rather than added to the field directly.

## `constrain()`: the typed escape hatch

For a rule the built-in vocabulary doesn't model, implement your own
`ConstraintInterface` value object (carrying whatever typed config the rule needs)
and attach it with `constrain()`:

```php
Str::make('coupon')->constrain(
    new RedeemableCoupon(context: \haddowg\JsonApi\Resource\Constraint\Context::onlyCreate()),
);
```

The constraint **is** the contract — an adapter translates it by matching on its
class, and the schema compiler skips constraints it doesn't recognise. Unlike the
fluent helpers, `constrain()` does **not** re-stamp the context, so scope a custom
constraint by constructing it with the `Context` you want (`onlyCreate()` /
`onlyUpdate()` / `always()`), as above. Constraints added inside a `when()` builder
are still captured into that `When` like any other.

## The core boundary

Core defines the constraint **vocabulary** and nothing more. It ships:

- **no executor** — core never validates a value against a constraint; that is a
  framework adapter's job (it translates the metadata and runs it natively);
- **no entity-level seam** — checks that need the persisted store (a
  `UniqueEntity`-style uniqueness rule, for instance) have no representation in the
  core vocabulary and live entirely in the adapter.

What core *does* run, for free, is the **structural** subset: the
[`SchemaCompiler`](schema-validation.md#per-resource-schemas-schemacompiler) turns the round-trippable
constraints into a per-resource JSON Schema that the optional
[document validator](schema-validation.md#validating-a-document) enforces. The non-structural
rules — `When`, `CompareField`, closure date bounds, and any `constrain()` VO —
are skipped by the compiler and carried as metadata for an adapter to execute.

## Next / see also

- [Fields](fields.md) — the fluent builders that emit these constraints, per field type.
- [Validation](schema-validation.md) — the `SchemaCompiler`, the document validator, and the validation middleware that run the structural subset.
- [Relations](relations.md) — where `RelationshipType` is declared via `type()` / `types()`.
- [Adapters](adapters.md) — translating the full vocabulary into a native validator and rendering `422`.
