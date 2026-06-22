# Fields: the shared builder surface

A field is one entry in a [resource](resources.md)'s `fields()` list. Each field
describes one member of a resource type — the `id`, an attribute, or a
[relationship](relations.md) — and that single declaration drives **both**
directions: how the member is serialized out of a domain object and how it is
hydrated back into one. This page documents the fluent surface that *every*
attribute field inherits from `Resource\Field\AbstractField`. Each concrete field
type ([field types](field-types.md)) adds only its type-specific delta on top.

```php
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

public function fields(): array
{
    return [
        Id::make(),
        Str::make('title')->required()->maxLength(200)->sortable(),
    ];
}
```

Fields are **mutable builders**: every fluent method mutates the field and
returns it, so a field reads as one chained expression and one `fields()` entry
configures serialize *and* hydrate at once.

## Naming and storage

You always construct a field through its static `make()` factory, never with
`new`. The first argument is the JSON:API member name; by default that name is
also the backing domain-object member.

| Method | Effect |
|---|---|
| `make(string $name)` | Constructs the field. The member name is also the default backing column. |
| `storedAs(string $column)` | Reads/writes a different domain-object member than the JSON:API member name. |
| `computed()` | Marks the field as having no backing column. Pair with `extractUsing()` for the read value. |
| `computedUsing(\Closure $cb)` | One-liner for a derived, read-only attribute: `computed()` + the value closure + `readOnly()`. |
| `on(string $path)` | Flattens a scalar attribute from a chain of declared, **to-one** relations' related model (`'author'` or `'publisher.country'`). |

[`TrackResource`](../examples/music-catalog/src/Resource/TrackResource.php)
exposes a `durationSeconds` member backed by a differently named column, and a
`displayTitle` member with no column at all:

```php
// The JSON:API member `durationSeconds` is stored on the domain object's
// `length_seconds` column — the rename round-trips through serialize and hydrate.
Integer::make('durationSeconds')->storedAs('length_seconds'),

// Computed: no backing column, derived on read via extractUsing().
Str::make('displayTitle')
    ->computed()
    ->readOnly()
    ->extractUsing(static fn(mixed $track): string => $track instanceof Track
        ? \sprintf('%d. %s', $track->trackNumber, $track->title)
        : ''),
```

### Derived attributes: `computedUsing()`

`computedUsing(\Closure $cb)` is the one-liner for the computed-and-read-only
pattern above: it marks the field `computed()` (no backing column), sets the value
closure, and marks it `readOnly()` on both create and update (a derived value has
nothing to write back). The closure receives `(mixed $model, $request, string $name)`
and owns the serialized output — no per-type cast is applied — so the two lines

```php
Str::make('displayTitle')->computed()->readOnly()->extractUsing($fn),
Str::make('displayTitle')->computedUsing($fn),
```

are equivalent. The lower-level `computed()` / `extractUsing()` / `serializeUsing()`
primitives remain available for cases the sugar does not cover (e.g. a computed
field that is *not* read-only, or one that wants per-type casting via `extractUsing`).

### Flattened related attributes: `on()`

`on(string $path)` flattens a scalar attribute from a **chain of declared, to-one**
relations' related model — the JSON:API resource carries the value inline while the
domain stores it on an associated record. `$path` is a `.`-separated chain of
relation names: `'author'` (single hop) or `'publisher.country'` (multi-hop). The
value is read from / written onto the **final** related model in the chain, using
the field's own `column() ?? name()`. Every segment must be a declared, to-one
[`RelationInterface`](relations.md); a segment **may be `hidden()`**, the idiomatic
"internal association" that backs a flattened attribute without ever rendering as a
relationship:

```php
// The book's resource exposes `authorName` inline; it lives on the related
// author. The `author` relation is hidden, so it is not a rendered relationship.
Str::make('authorName')->on('author')->storedAs('name'),
BelongsTo::make('author', 'authors')->hidden(),

// A multi-hop chain: book -> publisher -> country, reading the country's `name`.
Str::make('countryName')->on('publisher.country')->storedAs('name'),
BelongsTo::make('publisher', 'publishers')->hidden(),
```

- **Read.** The chain is walked hop by hop (each hop honouring its relation's
  `column()`/`storedAs()`), the field reads its own `column() ?? name()` off the
  final related object, and the normal per-type cast applies. **Any intermediate
  null short-circuits the chain → a null** attribute value.
- **Write.** The value is written onto the final related model (mutated in place —
  your ORM's unit of work persists the dirty loaded entity on flush). A flattened
  attribute **never auto-instantiates** a related model: writing one when **any hop**
  is absent is a **422** (`RelatedAttributeOwnerMissing`, pointing at
  `/data/attributes/<name>`). Flattened attributes hydrate **after** relationships,
  so a first-hop relation associated in the same request body is visible.

Every segment is validated **fail-loud at boot / container warm-up**: an unknown
segment, or a **to-many** segment at any depth, is a developer-facing
`\LogicException` (a to-many is not flattenable — use `?include` to materialise a
collection).

`on()` is mutually exclusive with `computedUsing()` / `extractUsing()` (a flattened
attribute reads its own backing member off the related object). For the host to
materialise the chain before serializing, the resource declares it as an eager load
automatically — see [Eager-loading](#eager-loading-related-models) below.

### Accessor resolution order

The backing member is resolved through a framework-agnostic accessor. For a
member named `genres` on an object, the read path tries, in order:

1. a `getGenres()` method,
2. an `isGenres()` method,
3. a public `genres` property;

and the write path tries a `setGenres()` method, then a public `genres`
property. Plain associative arrays and `ArrayAccess` objects are addressed by
key. You never wire any of this up explicitly — it is the zero-config default.
ORM entities with private properties and bespoke accessors are handled by a
field's `extractUsing()` / `fillUsing()` hooks (below), not by this helper.

## Visibility and query eligibility

| Method | Effect |
|---|---|
| `hidden()` | Drops the field from output **and** from hydration entirely. |
| `writeOnly()` | The inverse direction: accepted on write but **never rendered** (see below). |
| `notSparseField()` | Exempts the field from sparse-fieldset (`?fields[type]=…`) filtering, so it is always emitted. |
| `sortable()` | Marks the field as sortable; the resource's `allSorts()` derives a [`SortByField`](sorts.md) for it. |

`hidden()` removes the member from both directions. For the opposite asymmetry — a
member a client *sets* but the server never *echoes back*, a `password` or an API
token — reach for `writeOnly()`, covered next.

### Write-only members

`writeOnly()` marks a field write-only: it is **accepted on write** — hydrated on
both create and update, and still [validated](constraints.md) — but **never
rendered**. The member is dropped exactly where sparse-fieldset filtering happens,
so it appears on no read (single, collection, included, related) and is **absent,
not null**: a `fields[type]` parameter that names it cannot resurrect it.

[`UserResource`](../examples/music-catalog/src/Resource/UserResource.php)'s
`password` is the worked example:

```php
// users: accepted + validated on write, never rendered — absent, not null.
Str::make('password')->writeOnly(),
```

A POST carrying `password` hydrates it into the stored object, but the `201`
response (and every later `GET`) omits the member entirely — no `"password"` key.

`writeOnly()` and `readOnly()` are the two opposite asymmetries and **cannot
combine**: a field that is neither readable nor writable is contradictory, so
declaring both throws a `\LogicException`. Inside a [`Map`](field-types.md#map), a
write-only child is skipped on render just like a top-level write-only field, so it
too is absent from the nested object.

## Read-only scoping (gates hydration)

A read-only field is still serialized, but is **silently skipped** during
hydration in the matching context — a client value in the request body is
ignored, not rejected.

| Method | Effect |
|---|---|
| `readOnly()` | Read-only on both create and update. |
| `readOnlyOnCreate()` | Read-only on create (POST) only. |
| `readOnlyOnUpdate()` | Read-only on update (PATCH) only. |

[`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php)'s
`averageRating` is server-computed: a client value in the write body is dropped,
and a freshly created album keeps its domain default.

```php
Decimal::make('averageRating')->readOnly()->nullable(),
```

## Request-aware visibility and writability

`hidden()`, `readOnly()`/`readOnlyOnCreate()`/`readOnlyOnUpdate()` and `writeOnly()`
each also accept an **optional closure** that decides the restriction *per request* —
lightweight per-caller control without a security framework. The uniform convention:
the closure returns `true` when the restriction **applies**.

```php
// Hidden only from a non-admin caller. Model first, request second — the same
// argument order as every other field closure (extractUsing/computedUsing), so you
// never swap order between adjacent declarations.
Str::make('draftNote')->hidden(
    static fn(mixed $model, JsonApiRequestInterface $request): bool
        => $request->getHeaderLine('X-Role') !== 'admin',
),

// Write-gating predicates take only the request — a create has no persisted model.
Str::make('locked')->readOnlyOnUpdate(
    static fn(JsonApiRequestInterface $request): bool
        => $request->getHeaderLine('X-Role') !== 'admin',
),
```

A closure-declared field is **not unconditionally** restricted, so the static getters
(`isHidden()`, `isReadOnly()`, `isWriteOnly()`) report the permissive value and the
OpenAPI schema documents the **superset** (a sometimes-hidden field still appears;
a sometimes-prohibited verb is still exposed). The relation
authorization predicates `cannotReplace()`/`cannotRemove()`/`cannotAdd()`/
`cannotBeIncluded()` take the same `(mixed $model, JsonApiRequestInterface $request)`
closure — see [relations](relations.md).

## Presence (gates validation)

The presence helpers declare whether a member must appear, and whether it may be
`null`. They are **validation** metadata, scoped by request context. On a PATCH,
an absent member means "no change" — so `required()` and `requiredOnCreate()`
differ only on update.

| Method | Adds |
|---|---|
| `required()` | `Required` on both create and update. |
| `requiredOnCreate()` | `Required` on create (POST) only; absent on PATCH means "no change". |
| `requiredOnUpdate()` | `Required` when supplied on update (PATCH) only. |
| `nullable()` | `Nullable` — the member may be `null`. |

```php
Str::make('title')->required()->maxLength(200),
Date::make('birthDate')->nullable(),
```

### Two axes, not one

Read-only and required look adjacent but gate opposite directions, and they
compose:

- **Read-only** (`readOnly*`) gates **hydration** — the value is *skipped* if
  present.
- **Required** (`required*`) gates **validation** — the value is *demanded* if
  the [validation adapter](constraints.md) executes it.

A `readOnly()->nullable()` field is serialized, never accepts a client write, and
advertises that its value may be null. The two helpers never conflict because
they act in different phases.

## Enumeration

Every field can constrain its value to (or away from) a fixed set:

| Method | Adds |
|---|---|
| `in(array $values)` | `In` — value must be one of `$values`. |
| `notIn(array $values)` | `NotIn` — value must not be one of `$values`. |

```php
Str::make('status')->in(['draft', 'published', 'archived']),
```

## Context scoping

`onCreate()` / `onUpdate()` re-stamp **every** constraint appended inside the
closure with that request context, so you don't repeat the `…OnCreate` suffix on
each call:

```php
Str::make('slug')->onCreate(function (Str $field): void {
    $field->required()->maxLength(64);
});
```

Inside `onUpdate()`, an `Str::make('slug')->required()` becomes "required on
update only" — the closure context wins over the helper's default.

## Composition and cross-field rules

These appear on **every** field. They build up the constraint vocabulary that the
optional [validation adapter](constraints.md) executes; core itself never runs
them against data.

| Method | Effect |
|---|---|
| `constrain(ConstraintInterface ...$c)` | Attaches constraints directly — the typed escape hatch for rules the helpers don't cover, your own implementations included. Each constraint carries its own context; `constrain()` does **not** re-stamp it. |
| `sequentially(ConstraintInterface ...$c)` | Applies the constraints in order, stopping at the first failure; all must ultimately hold. |
| `atLeastOneOf(ConstraintInterface ...$alt)` | Passes if the value satisfies at least one alternative. |
| `when($condition, $builder)` | Applies the constraints appended inside `$builder` only when `$condition` returns true for the value. The wrapped rules fold into a single `When`; the condition is opaque PHP, so it does not round-trip to JSON Schema. |
| `compareWith(string $field, Comparison $op)` | Cross-field comparison: the operator reads `<this field> <op> <$field>`. `$op` is a [`Comparison` enum case](constraints.md#the-comparison-enum). |

[`UserResource`](../examples/music-catalog/src/Resource/UserResource.php)'s
`passwordConfirm` stacks all three of the composition forms in one chain. Unlike
the stored-but-write-only `password` field above, `passwordConfirm` is
`computed()` because it is a transient confirmation value that is only compared,
never persisted. `atLeastOneOf()`/`sequentially()`/`when()` take constraint value
objects directly (from the [constraint
vocabulary](constraints.md#the-constraint-vocabulary)), the same way `each(new
MinLength(1))` does in [field types](field-types.md):

```php
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Constraint\Pattern;

Str::make('passwordConfirm')
    ->computed()
    ->serializeUsing(fn(): ?string => null)
    ->atLeastOneOf(
        new MinLength(8),
        new Pattern('^.*[0-9].*$'),
    )
    ->when(
        static fn(mixed $value): bool => $value !== null && $value !== '',
        static function (Str $field): void {
            $field->minLength(8);
        },
    )
    ->compareWith('password', Comparison::EqualTo),
```

`compareWith` is directional. The album pair reads `availableUntil >
availableFrom` — this field is the **left** operand:

```php
Date::make('availableUntil')
    ->nullable()
    ->compareWith('availableFrom', Comparison::GreaterThan),
```

See [validation](constraints.md) for the full constraint vocabulary and the
create/update context model, and [field types](field-types.md) for the per-type
helpers (`minLength`, `min`, `before`, …) that wrap these same constraints.

## The four hooks

When the accessor and per-type casting aren't enough for a single member, replace
part of its read or write path with a closure. Two hooks customise reading, two
customise writing — reach for them before dropping to a whole-resource custom
[serializer](serializers.md) / [hydrator](hydrators.md).

| Hook | Closure signature | Replaces |
|---|---|---|
| `serializeUsing($fn)` | `fn(mixed $model, JsonApiRequestInterface $request, string $name): mixed` | The full read path (accessor + cast). |
| `extractUsing($fn)` | `fn(mixed $model, JsonApiRequestInterface $request, string $name): mixed` | The raw read; the field's per-type cast still applies. |
| `deserializeUsing($fn)` | `fn(mixed $value, array $data): mixed` | The per-type cast on hydration. |
| `fillUsing($fn)` | `fn(mixed $model, mixed $value, array $data, string $name): mixed` | The full write path; return the model (or `null` to keep it unchanged). |

On read, `serializeUsing` wins over `extractUsing`; on write, `fillUsing` wins
over `deserializeUsing`. The split matters: `extractUsing` lets per-type casting
finish the job, while `serializeUsing` owns the value outright (it is the place to
null a computed transient on read, as `passwordConfirm` above does). To suppress a
*stored* member from output entirely, prefer
[`writeOnly()`](#write-only-members) — it drops the member rather than rendering it
as `null`.

### Worked example: a computed read and a renamed column

[`TrackResource`](../examples/music-catalog/src/Resource/TrackResource.php) is the
most instructive declaration. `displayTitle` is `computed()` (no column) and
`readOnly()`, with its value assembled across two real columns purely on read via
`extractUsing()`; `durationSeconds` is a plain field whose column is renamed with
`storedAs()`, so the rename round-trips transparently through serialize and
hydrate without a hook at all:

```php
final class TrackResource extends AbstractResource
{
    public static string $type = 'tracks';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->sortable(),
            Integer::make('trackNumber')->min(1)->sortable(),
            Integer::make('durationSeconds')->storedAs('length_seconds'),
            // …
            Str::make('displayTitle')
                ->computed()
                ->readOnly()
                ->extractUsing(static fn(mixed $track): string => $track instanceof Track
                    ? \sprintf('%d. %s', $track->trackNumber, $track->title)
                    : ''),
            // …
        ];
    }
}
```

> The single-field hooks are the right tool for one member's quirk. When the
> *whole* read or write path needs hand-writing — a request-dependent attribute
> set, say — drop to a custom [serializer](serializers.md) or
> [hydrator](hydrators.md) instead. The example's
> [`TrackSerializer`](../examples/music-catalog/src/Serializer/TrackSerializer.php)
> is registered as a read override for exactly that reason, while the resource
> above still hydrates writes.

## Eager-loading related models

Every `on()` attribute's backing relation chain is **eager-loaded on every read** —
a load-not-render hint, distinct from `?include` (which renders into `included`) —
so the final related model is materialised before the flattened value is read. The
dedup set of every `on()` chain (in field order) is exposed via the
`DeclaresEagerLoadsInterface::eagerLoadRelationshipPaths()` capability (which
`AbstractResource` implements):

```php
Str::make('authorName')->on('author')->storedAs('name'),
Str::make('countryName')->on('publisher.country')->storedAs('name'),
// eagerLoadRelationshipPaths() => ['author', 'publisher.country']
```

A multi-hop chain (`'publisher.country'`) is walked segment by segment, batch-loaded
across the targets the previous level loaded (the same level-walk `?include` uses) —
O(depth) queries, never per-row. Because every segment is to-one, eager-loading never
flips a relationship's linkage rendering, so the document is unchanged. The core
library only **declares** the eager set; the host data layer executes the loading and
excludes it from `included` — an eager load changes the query plan, never the
document. Because the set is author-declared, a host may bypass the client-include
safeguards (depth cap, allowed-paths, `cannotBeIncluded`) for it.

### The eager set is validated at boot (fail loud)

The eager set is checked **at container warm-up** — so a mistake fails at
`cache:clear` / deploy, not as a runtime 500. `EagerLoadValidator` walks **every
segment of every** `on()` chain across types and throws a `\LogicException` when:

- a segment **names no declared relation** (a typo — it would otherwise silently
  no-op); or
- a segment is a **to-many** relation. `on()` flattens a single scalar from a to-one
  chain, so a to-many at any depth — including an **ancestor** in a dot-path, not
  just the leaf — is not flattenable. Use `?include` to materialise a collection
  instead.

A segment may be `hidden()` (the internal-association idiom) or visible — both pass,
because the chain is to-one. A polymorphic (`MorphTo`) or inventory-less segment
whose next type cannot be resolved to a single relation-declaring serializer is left
unwalked (skipped), not thrown — matching the include walk.

## Relationships are fields too

A relationship is declared in the same `fields()` list and produces the resource
object's `relationships` member. Relations inherit this shared surface (presence,
read-only scoping, context) and add their own linkage-shaped helpers. The
example's `BelongsTo::make('album', 'albums')` and
`HasMany::make('tracks', 'tracks')` sit alongside the attribute fields
above. See [relations](relations.md) for the relation field types and how the
[server registry](server.md) resolves the related resource.

## Next

- [Field types](field-types.md) — the per-type delta for each concrete field
  (`Str`, `Integer`, `DateTime`, `Map`, `ArrayList`, …).
- [Relations](relations.md) — relationship field types (`BelongsTo`, `HasMany`,
  `MorphTo`, …) and the registry.
- [Resources](resources.md) — how `fields()` drives the serialize and hydrate
  walks, id generation, and registration.
- [Validation](constraints.md) — the constraint vocabulary and the create/update
  context model the presence and composition helpers feed.
