# Field types reference

Every concrete field type inherits the shared fluent surface documented in
[fields](fields.md) — `make()`, `storedAs()`, the read-only and constraint
shortcuts, the four serialize/hydrate hooks. This page is the per-type reference:
each section shows only the **delta** a type adds on top of that surface — its
casting behaviour and its type-specific constraint helpers. Reach for it when you
are picking the right field for a member.

All the snippets below are lifted from the music-catalog example app — the
[`TrackResource`](../examples/music-catalog/src/Resource/TrackResource.php),
[`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php) and
[`UserResource`](../examples/music-catalog/src/Resource/UserResource.php) between
them exercise every type here.

## `Str`

A generic string attribute, and the base class for the format subtypes below.
Adds length, pattern and the five format shortcuts.

| Method | Adds |
|---|---|
| `minLength(int)` / `maxLength(int)` | `MinLength` / `MaxLength`. |
| `pattern(string $regex)` | `Pattern`. |
| `email(bool $strict = false)` | `EmailFormat`. |
| `url(array $allowedSchemes = [])` | `UrlFormat`. |
| `uuid(?int $version = null)` | `UuidFormat`. |
| `slug(?string $regex = null)` | `SlugFormat`. |
| `ip(?int $version = null)` | `IpFormat`. |

```php
Str::make('title')->required()->maxLength(200)->sortable();
```

The five format shortcuts produce exactly the same constraint metadata as the
dedicated [`Email` / `Url` / `Uuid` / `Slug` / `Ip`](#email-url-uuid-slug-ip)
field types — `Str::make('email')->email()` is interchangeable with
`Email::make('email')`. Use the shortcut when the field also carries other string
rules in the same chain; use the dedicated type when the field is *only* that
format. (This equivalence is stated once, here.)

## `Integer`

An integer attribute (JSON `type: integer`); casts to `int` on both serialize and
hydrate. The [`trackNumber`](../examples/music-catalog/src/Resource/TrackResource.php)
and `durationSeconds` members are integers — the wire value `1` round-trips to a
PHP `int`.

| Method | Adds |
|---|---|
| `min(int)` / `max(int)` | `Min` / `Max`. |
| `exclusiveMin(int)` / `exclusiveMax(int)` | `ExclusiveMin` / `ExclusiveMax`. |
| `multipleOf(int)` | `MultipleOf`. |
| `in(array $values)` | `In`. |

```php
Integer::make('trackNumber')->min(1)->sortable();
Integer::make('durationSeconds')->storedAs('length_seconds');
```

## `Decimal`

A floating-point attribute (JSON `type: number`); casts to `float`. The bound
helpers accept `int|float`. The album's
[`averageRating`](../examples/music-catalog/src/Resource/AlbumResource.php) is a
`Decimal` (a nullable, read-only rating).

| Method | Adds |
|---|---|
| `min(int\|float)` / `max(int\|float)` | `Min` / `Max`. |
| `exclusiveMin(int\|float)` / `exclusiveMax(int\|float)` | `ExclusiveMin` / `ExclusiveMax`. |
| `multipleOf(int\|float)` | `MultipleOf`. |
| `in(array $values)` | `In`. |

```php
Decimal::make('averageRating')->readOnly()->nullable();
```

## `Boolean`

A boolean attribute; casts to `bool`. No type-specific constraint helpers — its
whole job is the bidirectional `bool` cast. The track's
[`explicit`](../examples/music-catalog/src/Resource/TrackResource.php) flag is a
`Boolean`.

```php
Boolean::make('explicit');
```

## `DateTime`

`DateTime` serializes a `\DateTimeInterface` to a string and hydrates a string
back to a `\DateTimeImmutable`. The default format is ISO-8601
(`\DateTimeInterface::ATOM`).

| Method | Adds / effect |
|---|---|
| `format(string)` | Override the serialization format string (default `\DateTimeInterface::ATOM`). |
| `before($bound)` / `after($bound)` | `Before` / `After`. |
| `between($min, $max)` | `Between`. |
| `useTimezone(string)` | Convert hydrated values into the given timezone before storing. |

`before()`, `after()` and `between()` accept either a fixed `\DateTimeInterface`
**or** a `\Closure(): \DateTimeInterface`. A closure bound is evaluated at
validation time, so it reflects the moment of the request — and because it is
opaque PHP, it does **not** round-trip to JSON Schema.

The album's [`releasedAt`](../examples/music-catalog/src/Resource/AlbumResource.php)
is the worked example: a closure `before` bound forbidding future release dates,
plus a timezone normalisation to UTC.

```php
DateTime::make('releasedAt')
    ->before(static fn(): \DateTimeImmutable => new \DateTimeImmutable())
    ->useTimezone('UTC')
    ->sortable();
```

Because `new \DateTimeImmutable()` is resolved each time the rule runs, "no future
releases" always means *now*, per request — not the moment the resource class was
loaded. The same closure form works for `after()` and for either bound of
`between()`.

## `Date`

A `DateTime` fixed to the `Y-m-d` format — a calendar date with no time
component. It inherits every `DateTime` helper. The user's
[`birthDate`](../examples/music-catalog/src/Resource/UserResource.php) is a
nullable `Date`.

```php
Date::make('birthDate')->nullable();
```

## `Time`

A `DateTime` fixed to the `H:i:s` format — a wall-clock time. The track's
[`previewOffset`](../examples/music-catalog/src/Resource/TrackResource.php) (the
offset into the track where its preview starts) is a nullable `Time`.

```php
Time::make('previewOffset')->nullable();
```

## `Map`

`Map` exposes a nested JSON object in the resource attributes while spreading its
values across multiple **flat columns on the same domain object**. Each child
field reads and writes its own column; the child's name is the key inside the
nested object. Declare the children with `fields()`.

| Method | Effect |
|---|---|
| `fields(FieldInterface ...$children)` | Declares the child fields. Each child maps to its own flat column. |

The album's [`releaseInfo`](../examples/music-catalog/src/Resource/AlbumResource.php)
is the worked example: it presents as a nested object `{ "label": …,
"catalogueNumber": … }`, but the two children read and write the flat `label` and
`catalogueNumber` columns on the [`Album`](../examples/music-catalog/src/Domain/Album.php).
The `catalogueNumber` child is `readOnly()`, so a value supplied through the
nested object on write is ignored.

```php
Map::make('releaseInfo')->fields(
    Str::make('label'),
    Str::make('catalogueNumber')->readOnly(),
);
```

Top-level constraints on a `Map` are limited to presence — `required()` and
`nullable()`. Every structural rule belongs on a child field, and a child
violation surfaces as a nested
`/data/attributes/releaseInfo/<child>` source pointer (so a bad `catalogueNumber`
points at `/data/attributes/releaseInfo/catalogueNumber`, not at the map).

> `Map::on($relation)` — spreading children across a **related** model rather than
> the same one — is out of scope for core 1.0.

## `ArrayList`

A zero-indexed array attribute (JSON `type: array`).

| Method | Adds / effect |
|---|---|
| `minItems(int)` / `maxItems(int)` | `MinItems` / `MaxItems`. |
| `uniqueItems()` | `UniqueItems`. |
| `each(ConstraintInterface ...$constraints)` | `Each` — applies the given constraints to every item. |
| `sorted()` | Sorts the list on serialization. |

The track's [`genres`](../examples/music-catalog/src/Resource/TrackResource.php)
is the worked example — at least one genre, each a non-empty string, no
duplicates. Note that `each()` takes constraint **value objects** (not a nested
field), so the per-item rule is `new MinLength(1)`:

```php
use haddowg\JsonApi\Resource\Constraint\MinLength;

ArrayList::make('genres')
    ->minItems(1)
    ->each(new MinLength(1))
    ->uniqueItems();
```

## `ArrayHash`

A JSON object attribute exposed as a PHP associative array (JSON `type: object`)
with **dynamic keys** — the keys are open-ended data, not declared field names.

| Method | Adds / effect |
|---|---|
| `minProperties(int)` / `maxProperties(int)` | `MinProperties` / `MaxProperties`. |
| `sortKeys()` | Sort by key on serialization. |
| `sortValues()` | Sort by value on serialization (keys preserved). |

The user's [`preferences`](../examples/music-catalog/src/Resource/UserResource.php)
is an `ArrayHash`, sorted by key for a stable wire shape:

```php
ArrayHash::make('preferences')->minProperties(0)->maxProperties(20)->sortKeys();
```

**`Map` vs `ArrayHash`** is the key distinction to get right. Both render a JSON
object, but `Map` has **declared keys** — each child is its own field, mapping to
its own flat column, validated and cast independently. `ArrayHash` has **dynamic
keys** — an open bag of properties stored and emitted as one associative array.
Use `Map` for a fixed nested shape (an address, release info); use `ArrayHash`
for arbitrary user-supplied key/value data (preferences, settings).

## `Email`, `Url`, `Uuid`, `Slug`, `Ip`

Each is a `Str` whose `make()` **pre-attaches** the matching format constraint —
so `Email::make('email')` already carries an `EmailFormat` before you chain
anything. They are pure sugar over the `Str` format shortcuts, plus one
type-specific helper each.

| Type | Equivalent to | Extra helper |
|---|---|---|
| `Email::make($name)` | `Str::make($name)->email()` | `strict()` — opt into RFC-strict validation. |
| `Url::make($name)` | `Str::make($name)->url()` | `allowedSchemes(string ...$schemes)` — restrict the URI schemes. |
| `Uuid::make($name)` | `Str::make($name)->uuid()` | `version(int)` — narrow to a UUID version. |
| `Slug::make($name)` | `Str::make($name)->slug()` | — |
| `Ip::make($name)` | `Str::make($name)->ip()` | `v4()` / `v6()` / `both()` — narrow the IP version (default both). |

The user's [`email`](../examples/music-catalog/src/Resource/UserResource.php) and
`lastSeenIp` demonstrate two of them:

```php
Email::make('email')->required()->strict();
Ip::make('lastSeenIp')->nullable();
```

**Reconcile, not stack.** The extra helpers *replace* the pre-attached format
constraint rather than adding a second one. `Email::make()->strict()` does not end
up with two `EmailFormat` rules — `strict()` removes the lax one `make()` attached
and adds a single strict `EmailFormat`. Likewise `Url::make()->allowedSchemes('https')`
re-attaches a single `UrlFormat` with the scheme list, and `Ip::make()->v4()`
narrows to one IPv4 `IpFormat`. So a chain like `->strict()` always leaves exactly
one format constraint on the field.

## Next

- [Fields](fields.md) — the shared fluent surface every type above inherits
  (naming, visibility, read-only contexts, constraint shortcuts, the four hooks).
- [Ids](ids.md) — the `Id` field and the id lifecycle (it is special-cased into
  the top-level `id`, not an attribute).
- [Relations](relations.md) — the relationship field types and the relation DSL.
- [Validation](constraints.md) — what the constraint metadata each type adds
  actually does, and the create/update context model.
