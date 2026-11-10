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

## Every value must have its field's JSON type

A written value must already have the JSON type its field's write schema documents: a
string for `Str` and its facades, the date fields and a string enum, an integer for
`Integer`, a number for `Decimal`, a boolean for `Boolean`, a list for `ArrayList`, an
object for `Obj`, `OneOf`, `Map` and `ArrayHash`, and one of its members' types for a
[`Union`](#union). A list's elements take the type `of()` gives them. The casts never convert between types, so `"1"` for an `Integer`, `"yes"`
or `1` for a `Boolean` and `42` for a `Str` each get a `422` at the value's pointer.
`null` passes only where the field is `nullable()`. A whole float such as `3.0` counts
as an integer, and an integer counts as a number.

The adapters check this before they hydrate. `ValueTypes::mismatchesIn()` walks a write
body's attributes, with every child, open member, list element and `OneOf` variant
below them, and returns each value of the wrong type with its pointer. An adapter
reports each one as the only error at that pointer, ahead of the field's other rules,
so `42` sent for a `Str` with a `maxLength()` gets one error about its type. A list sent
for an object is the exception, as [below](#a-json-array-is-not-an-object). Filters are
not affected, since a query string is always text.

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

Hydration reads the string with
[`DateTimeParser`](../src/Resource/DateTimeParser.php). It accepts the strings
`new \DateTimeImmutable()` accepts, except one that names a day or time that does not
exist. PHP would quietly roll `2026-02-30` over to 2 March, and `T24:00:00` to the next
midnight. The parser refuses both, so the client gets a `422`
`ATTRIBUTE_VALUE_INVALID` at `/data/attributes/<name>` instead of a stored date it never
sent. `Date` and `Time` share the same cast.

| Method | Adds / effect |
|---|---|
| `format(string)` | Override the serialization format string (default `\DateTimeInterface::ATOM`). |
| `before($bound)` / `after($bound)` | `Before` / `After`. |
| `between($min, $max)` | `Between`. |
| `inputTimezone(string)` | Convert hydrated values into the given timezone before storing. |
| `outputTimezone(string)` | Convert serialized values into the given timezone before formatting. See [timezones](#timezones-on-the-way-in-and-out). |
| `useTimezone(string)` | Deprecated alias of `inputTimezone()`. |

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
    ->inputTimezone('UTC')
    ->sortable();
```

Because `new \DateTimeImmutable()` is resolved each time the rule runs, "no future
releases" always means *now*, per request — not the moment the resource class was
loaded. The same closure form works for `after()` and for either bound of
`between()`.

### Nested dates are stored as strings

A top-level `DateTime`, `Date` or `Time` hydrates to a `\DateTimeImmutable` on the
model, where your ORM maps it to a date column. A date child of an `Obj`, a `OneOf`
variant or a structured `ArrayList` element, at any depth, hydrates to a string
instead. That value lives inside one JSON column or array cast, and `json_encode`
would write an object out as PHP's `{date, timezone_type, timezone}` dump, which no
other reader of the column understands.

- A `DateTime` child stores its instant in UTC with microseconds and offset, in the
  format `Y-m-d\TH:i:s.uP`, such as `2026-11-03T15:52:53.000000+00:00`. The value is
  parsed and moved by `inputTimezone()` first, as a top-level one is, so the instant
  is the one the client sent.
- A `Date` or `Time` child stores its value in the field's own format, `Y-m-d` or
  `H:i:s` unless you set another, with no zone conversion, so the day or the time of
  day stays the one the client sent.

So code that reads a nested date off the model gets a string, not a
`\DateTimeImmutable`. A child's own `fillUsing()` or `deserializeUsing()`, or a
`fillUsing()` on the parent, still decides what is stored.

On a read a stored `DateTime` string moves into the field's `outputTimezone()` or the
server's [default output zone](server.md#output-timezone), and renders as the object
did. With neither set it renders in PHP's default zone, `date_default_timezone_get()`.
That is the zone an ORM hands back a naive date-time column in, so a nested date
renders as a top-level one does. A `Date` or `Time` string is already in wire form and
renders unchanged.

### Reading a stored value back

Serialization formats a `\DateTimeInterface`. It also reads the shapes a JSON column
hands back for one. That covers the strings nested dates are stored as, and an object
or its dump that something else left in the column, so a `PATCH` that merges onto such
an `Obj` renders both.

- An ISO 8601 date-time string that carries its offset or `Z`, such as
  `2024-07-01T12:30:00+02:00`, is parsed and rendered again in the field's format. A
  `DateTime` moves it into PHP's default zone first. A `Date` or `Time` keeps the
  string's own offset, so its day or time of day does not move.
- PHP's dump of a date object, `{"date": "2024-07-01 12:30:00.000000",
  "timezone_type": 3, "timezone": "Europe/Paris"}`, is rebuilt as that instant in its
  stored zone.

Either one then moves into the output zone and takes the field's format exactly as an
object does. Any other value renders as stored, including a string with no offset,
since it names no instant without guessing a zone. A string from `computedUsing()` or
`extractUsing()` is taken as already formatted and also renders unchanged.

### Timezones on the way in and out

`inputTimezone()` and `outputTimezone()` act on opposite directions and are
independent. `inputTimezone()` moves a value the client sent into a zone before it
reaches your domain object. `outputTimezone()` moves the domain value into a zone
before it is formatted for the response. Without `outputTimezone()`, a value renders
in whatever zone it carries, and a stored date-time string in PHP's default zone.

```php
// Stored in the enterprise's local zone, always rendered in UTC.
DateTime::make('createdAt')->outputTimezone('UTC');
```

The conversion runs on a `\DateTimeImmutable` copy, so a mutable `\DateTime` on your
entity keeps its zone. An unknown zone name throws an `\InvalidArgumentException`
when the field is declared, not when the first response renders.

To render every date-time in one zone, set it once on the server with
[`withOutputTimezone()`](server.md#output-timezone). A field's own
`outputTimezone()` beats the server's, so one field can still render in another
zone.

The server default reaches `DateTime` fields only, never `Date` or `Time`. That is
deliberate. A calendar date stored as midnight in Paris falls on the previous evening
in UTC, so converting it would render the wrong date. A bare time of day has no date to
convert against. If a `Date` or `Time` really should move, give that field its own
`outputTimezone()`.

Under UTC a format with a literal `Z` (`'Y-m-d\TH:i:s\Z'`) is honest, so a field that
pairs it with `outputTimezone('UTC')` keeps `format: date-time` in the generated
schemas. Without the conversion the same format is documented as a plain string.

### What `format()` does to the generated schemas

The `format: date-time`, `format: date` and `format: time` keywords are all defined
as RFC 3339 productions, so the [OpenAPI projection](openapi.md) and the
[body-validation schema](schema-validation.md) emit one only when your configured
format really writes that shape. `schemaFormat()` answers it by rendering a set of
reference instants and checking the output, so an equivalent spelling
(`\DateTimeInterface::RFC3339`, `RFC3339_EXTENDED`, a hand-rolled
`'Y-m-d\TH:i:s.uP'`) keeps its keyword.

Anything else is documented as a plain `string` whose shape is described by example:

```php
DateTime::make('archivedAt')->format('d/m/Y H:i');
```

```jsonc
{
  "type": "string",
  "description": "A date/time value written as `05/09/2024 09:08`. No standard `format` keyword describes that shape, so parse it literally rather than as an RFC 3339 date-time."
}
```

No `pattern` is derived. A PHP format string can render variable-width fields and
open-ended timezone names, so a regex inferred from one would eventually reject a
response your server is entitled to send.

## `Date`

A `DateTime` fixed to the `Y-m-d` format — a calendar date with no time
component. It inherits every `DateTime` helper. The user's
[`birthDate`](../examples/music-catalog/src/Resource/UserResource.php) is a
nullable `Date`.

```php
Date::make('birthDate')->nullable();
```

`Y-m-d` is RFC 3339 `full-date`, so the default projects as `format: date`.

A `Date` ignores the server's [default output zone](#timezones-on-the-way-in-and-out),
because converting a calendar date across zones can move it to another day.

## `Time`

A `DateTime` fixed to the `H:i:s` format — a wall-clock time. The track's
[`previewOffset`](../examples/music-catalog/src/Resource/TrackResource.php) (the
offset into the track where its preview starts) is a nullable `Time`.

```php
Time::make('previewOffset')->nullable();
```

`format: time` is RFC 3339 `full-time`, which **requires** a time-offset, so the
`H:i:s` default does not qualify and projects as a plain string with its shape in the
description. That is the honest answer: JSON Schema 2020-12 has no keyword for an
offsetless wall-clock time. Give the field an offset with `->format('H:i:sP')` if you
want the keyword and your values carry a meaningful zone.

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

A read-only child is skipped on hydration per the operation context, exactly as a
top-level field is — a `readOnly()` child can't be written through the nested
object on create or update, and `readOnlyOnCreate()`/`readOnlyOnUpdate()` each gate
only their own context. The [OpenAPI](openapi.md) write shapes leave such a child out
of the nested object for the same contexts.

The other visibility rules apply to a child the same way too. A `hidden()` child is
neither rendered nor written, a `writeOnly()` child is written but never rendered, and
the request-aware forms (`hidden(when:)`, `writeOnly(when:)`, `readOnly(when:)`) are
evaluated per request. A `hidden(when:)` predicate receives the value the child reads
from: the domain object for a `Map` child, the stored object for an `Obj` or `OneOf`
child.

> `Map::on($relation)` — spreading children across a **related** model rather than
> the same one — is out of scope for core.

## `Obj`

A typed nested object stored in a **single** backing value — one JSON column, one
array property. It is the single-value sibling of `Map`: where `Map` spreads its
children across separate flat columns and the nested object is only a wire view,
`Obj` keeps the whole object as one value and its children address keys **inside**
it. Use it when a structured attribute's natural storage is a single JSON document.

Like `Map` and each `OneOf` branch, the object is **closed** by default: its schema admits
only the declared children, so a key the client sends that no child declares is rejected
rather than dropped. [`additionalProperties()`](#open-members) opens it.

| Method | Effect |
|---|---|
| `fields(FieldInterface ...$children)` | Declares the child fields. Each child reads and writes a key inside the single value. |
| `additionalProperties(FieldInterface $value)` | Opens the object: every member no child declares is read, written and validated through `$value`. See [open members](#open-members). |
| `minProperties(int)` / `maxProperties(int)` | `MinProperties` / `MaxProperties`, counting every member the object carries, declared and additional. |
| `children()` | The declared children (for adapters and projection). |

```php
Obj::make('address')->nullable()->fields(
    Str::make('street')->required(),
    Str::make('city')->required(),
    Str::make('postcode')->required()->maxLength(10),
);
```

The child fields carry the typing and the constraints, exactly as `Map` children
do — a child violation surfaces as a nested `/data/attributes/address/<child>`
source pointer, and a read-only child is skipped on hydration per the operation
context. Top-level constraints on the `Obj` itself are limited to presence
(`required()` / `nullable()`) and the member count (`minProperties()` /
`maxProperties()`).

Write semantics on the single value:

- a **partial `PATCH` merges per-child** — an un-supplied child keeps its stored
  value (the single-value twin of `Map`'s per-column merge);
- an **explicit `null` clears the whole object**;
- a `DateTime`, `Date` or `Time` child stores a string, a `DateTime` its instant in
  UTC. See [nested dates](#nested-dates-are-stored-as-strings);
- the field-level `serializeUsing()`/`fillUsing()` hooks remain the escape hatch
  when a child value needs a richer PHP type than JSON can hold, such as a value
  object.

A value from `computedUsing()` or `extractUsing()` goes through the declared children
the same way a stored value does, so a `DateTime` child renders its formatted string
and a child reads its own key from the computed array or object. A declared child the
computed value leaves out stays absent, a hidden or write-only child is dropped, and a
key no child declares passes through as returned. `serializeUsing()` still owns the
whole value and skips the children.

In the generated OpenAPI document an `Obj` projects a typed `object` schema built
from its children (it self-describes via `ProvidesFieldSchema`). A `Map` projects the
same way. The create schema requires each child a create must supply, the update schema
requires none, and the read schema requires every child the object renders, which is
all of them except a write-only or request-conditional child. A field with its own
`serializeUsing()` / `extractUsing()` hook requires no children on read, and a `OneOf`
with one doesn't require its discriminator either. The schema still lists the
children's properties, and nothing checks a `serializeUsing()` return value against
them, so a hook that returns another shape puts a value on the wire the document
doesn't describe. See [presence on read](openapi.md).

### Open members

`additionalProperties()` takes a field that describes every member the object does not
declare. Its name is never used, so name it `'*'`. Each undeclared member's value is
validated by that field's constraints, cast by it on the way in and out, and stored under
its own key in the same associative array as the declared children. An inline spreadsheet
import, where each row is keyed by the user's own column headers, is a list of them:

```php
ArrayList::make('rows')
    ->of(Obj::make('row')
        ->additionalProperties(Str::make('*')->maxLength(200))
        ->maxProperties(50))
    ->maxItems(1000);
```

```json
"rows": [{ "Key": "RFP-123", "Vendor": "Acme Media", "CPL": "42" }]
```

Declared children can sit beside the open members. A key a child declares, by its name
or its `storedAs()` key, always goes to that child, so `Obj::make('row')->fields(
Str::make('Key')->required())->additionalProperties(Str::make('*'))` requires `Key` and
takes any other header as a string. `minProperties()` and `maxProperties()` count every
member the client sends, declared and additional.

The member field follows a child's rules. A `nullable()` one admits `null` members, a
`DateTime` one stores each member as a string, as a nested date child does, and a
`readOnly()` one renders the members but ignores them on a write. A `writeOnly()` or
`hidden()` one keeps them off the wire. It must be a plain attribute: a relation, an
`on()` path, a `computedUsing()` field or one with its own hook throws a `LogicException`
when the object is built, since there is no domain object behind one member to hook into.
Put a hook on the object instead.

An open object works wherever an `Obj` does: as an attribute, as a child of another `Obj`
or a `Map`, as a structured list element, inside a `OneOf` variant, and as the member
field of another open object. A `OneOf` variant itself stays closed, since an open branch
would match values meant for the other branches. A partial `PATCH` merges per member as it
merges per child, so a member the request leaves out keeps its stored value.

An adapter validates each member and the counts at the member's pointer, its key escaped
as a JSON Pointer token, so a `Unit/Price` header in the fourth row reports at
`/data/attributes/rows/3/Unit~1Price`. `ObjectValues::in()` finds every open or bounded
object in a write body for it, at any depth.

In the OpenAPI document the object projects `additionalProperties` as the member field's
own schema, and `minProperties` / `maxProperties`, as [the OpenAPI guide](openapi.md)
describes.

**`Map` vs `Obj` vs `ArrayHash`**: `Map` = declared keys, flat columns. `Obj` =
declared keys, one value, optionally open to typed undeclared ones. `ArrayHash` (below) =
dynamic keys, one value.

## `OneOf`

A **union** attribute: one of several declared object shapes (variants), stored,
like `Obj`, as a single backing value. A union is either **discriminated**, where a
property inside the value names the variant, or **undiscriminated**, where the
members the value carries say which variant it is.

| Method | Effect |
|---|---|
| `discriminator(string $property)` | Names the property whose value selects the variant. Leave it out for an undiscriminated union. |
| `variant(string $name, FieldInterface ...$children)` | Declares a variant: its name (the discriminator value that selects it, when there is one) and its child fields. |

```php
OneOf::make('block')->nullable()->discriminator('kind')
    ->variant('heading', Str::make('text')->required(), Integer::make('level')->min(1)->max(6))
    ->variant('image', Url::make('src')->required(), Str::make('alt'));
```

Only the **selected** variant's children run: a `heading` block validates and
hydrates `text`/`level`, an `image` block `src`/`alt`. On a partial `PATCH` the
incoming discriminator falls back to the stored one. The same variant merges
per-child, and a **different** variant starts fresh, so stale keys from the old variant
are not carried over. An unknown or missing discriminator has no variant to
hydrate through, so the raw value is stored for the adapter's validator to reject
(hydration never validates). The host surfaces it as a `422` pointing at
`/data/attributes/<field>/<discriminator>`.

In the generated OpenAPI document a discriminated `OneOf` projects `oneOf` + a
`discriminator` object, each branch carrying the discriminator as a `const` (and, on
create, in its `required` list), so a consumer can match a value to its variant
statically.

### Undiscriminated unions

Leave out `discriminator()` and the value's own members select the variant. Each
variant must then declare a **signature**: a member no other variant declares that is
`required()` on every write and always rendered (not hidden, write-only or conditionally
visible). A variant without one is rejected when the resource is built, since nothing
could tell its values apart from another variant's.

```php
OneOf::make('shape')
    ->variant('circle', Integer::make('radius')->required())
    ->variant('rectangle', Integer::make('width')->required(), Integer::make('height')->required());
```

A value carrying `radius` is a circle and one carrying `width` or `height` a rectangle.
One carrying the signatures of both selects neither, and the adapter's validator
rejects it at `/data/attributes/<field>`.

There is no stored discriminator to fall back to, so every write **replaces the whole
value**: a `PATCH` must send the complete variant, and its update schema requires what
the variant requires, as a [structured list element](#lists-of-structured-elements)
does. Each `oneOf` branch is closed, requires its signature and carries the variant's
name as its `title`, so exactly one branch can match any value and a code generator can
name the branch it reads. Prefer a discriminator when clients need to update one member of
a large variant.

For asserting a shape over a **free-form** value instead of declaring fields —
including unions of raw member schemas — see the
[`Shape` composite-schema constraint](constraints.md#shape-composite-schema-constraints).

## `ArrayList`

A zero-indexed array attribute (JSON `type: array`).

| Method | Adds / effect |
|---|---|
| `of(string $elementType)` | Types the projected `items` schema as `string`, `integer`, `number` or `boolean` (default `string`, so a list never projects as untyped); it does not cast values. |
| `of(Obj\|OneOf $element)` | Makes a list of [structured elements](#lists-of-structured-elements), each read and written through the shape's children. Builders are accepted too. |
| `of(Union $element)` | Makes a [list of unions](#lists-of-unions): each element is a string, number, boolean or list, as the union's members allow. Pass an unnamed `Union::of(...)`. |
| `minItems(int)` / `maxItems(int)` | `MinItems` / `MaxItems`. |
| `uniqueItems()` | `UniqueItems`. |
| `each(ConstraintInterface ...$constraints)` | `Each` — applies the given constraints to every item. Scalar lists only. |
| `sorted()` | Sorts the list on serialization. Scalar lists only. |
| `element()` | The built `Obj` or `OneOf` of a structured list, `null` for a scalar one (for adapters and projection). |
| `elementUnion()` | The built `Union` of a list of unions, `null` otherwise. |

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

### Lists of structured elements

Pass an `Obj` or a `OneOf` to `of()` and every element of the list takes that shape.
A list of edits, each one either a bare `trim` or a `find_replace` carrying its
parameters, looks like this:

```php
ArrayList::make('steps')
    ->of(OneOf::make('step')
        ->discriminator('operation')
        ->variant('trim')
        ->variant('find_replace', Obj::make('params')->fields(
            Str::make('find')->guaranteed(),
            Str::make('replace')->guaranteed(),
        )))
    ->maxItems(50);
```

and accepts

```json
"steps": [
  { "operation": "trim" },
  { "operation": "find_replace", "params": { "find": "colour", "replace": "color" } }
]
```

Each element behaves as the `Obj` or `OneOf` would as an attribute of its own. Its
children read and write keys inside the element, so a child's `storedAs()`,
`fillUsing()`, `deserializeUsing()`, `extractUsing()` and `serializeUsing()` hooks, a
`DateTime` child's parsing and formatting, and the `hidden()`, `writeOnly()` and
`readOnly()` rules all apply per element. A `OneOf` element picks its variant from its
own discriminator. The element's name (`step` above) never reaches the wire.

A write replaces the whole list. Every element is built fresh from the request, in the
order sent, and is never merged with the stored element at the same index, because a
list has no identity to match elements by. A child that the operation may not write,
such as a `readOnlyOnUpdate()` one on a `PATCH`, is left out of the new element rather
than carried over. An empty list stores `[]` and a `null` clears the list.

Hooks belong on the list or on the element's children, not on the element itself.
`extractUsing()` or `computedUsing()` on the list supplies the elements, and each one
still renders through the children, as a computed `Obj` does. `serializeUsing()` and
`fillUsing()` on the list own the whole value: the hook reads or stores it instead of
the elements' children. A write is still validated against the element, with its open
members and counts, and the write schema still describes it. An element
carrying its own hook, `each()` and `sorted()` all throw a `LogicException` when the
list is built: put per-element rules on the children, and keep the order the client
sends. A list nested inside an element works the same way, one level down.

The structural checks `Obj` and `OneOf` make on write apply to each element, with the
index in the pointer. A JSON object sent for the list is refused with a `422`
`ATTRIBUTE_VALUE_INVALID` at `/data/attributes/steps`, an element sent as a list at
`/data/attributes/steps/2`, and a child of an element at
`/data/attributes/steps/2/params`. An empty `{}` decodes to the same empty array as
`[]`, so it reads as an empty list. As with a `OneOf` attribute, an element naming no
variant is stored as sent for the adapter's validator to reject, and so is a scalar or
`null` element. Rule validation of the
children (presence, enums, lengths) is the adapter's job, which reaches the shape
through `element()` and validates each element at `<list>/<index>`.

In the generated OpenAPI document the list's `items` is the element's schema, built as
the `Obj` or `OneOf` would project as an attribute, and the list's own `minItems`,
`maxItems` and `uniqueItems` still apply. Because an update builds each element whole,
the update schema requires the same children and discriminator inside an element that
a create does. An operation example builds each element from its children's examples,
so a child with no example is reported missing, as an `Obj` child is.

## `Union`

A value that can be one of several JSON primitive types, or a list of them. Reach for it
when the type depends on data you don't know until runtime, such as a setting whose value
is a label, an amount, a flag or a list of those. A `OneOf` won't do, since its variants
are objects, and an untyped field throws away what you do know.

| Method | Effect |
|---|---|
| `of(FieldInterface ...$members)` | Declares the members, at most one per JSON type. |
| `Union::of(...$members)` | An unnamed union, for a list's elements. |
| `members()` | The built members, ordered string, number, boolean, array. |
| `memberFor(mixed $value)` | The member a written value's JSON type selects, or `null`. |
| `memberForStored(mixed $value)` | The member a stored value's PHP type selects, or `null`. |

```php
Union::make('value')->of(
    Str::make()->maxLength(200),
    Decimal::make(),
    Boolean::make(),
    ArrayList::make()->of(Union::of(Str::make(), Decimal::make(), Boolean::make())),
)->nullable();
```

accepts `"gold"`, `2.5`, `true`, `["gold", 2.5, false]` and `null`.

A member is an unnamed field, so `make()` takes no name. Its name is never used. It can
be one of four kinds:

- a string-family field: `Str` or one of its facades (`Email`, `Url`, `Uuid`, `Slug`,
  `Ip`), or `DateTime`, `Date` or `Time`;
- a number: `Integer` or `Decimal`;
- a `Boolean`;
- an `ArrayList` of scalars, or of a nested `Union::of()`.

`Obj`, `OneOf`, `Map`, `ArrayHash` and a list of objects are refused, and so is a union
member directly. There is no discriminator.

### One member per JSON type

A union takes at most one string member, one number member, one boolean and one list. An
`Integer` and a `Decimal` count as the same type, so a union can't hold both. A second
member of a type throws a `LogicException` when the union is built.

The rule is what keeps a union simple to reason about. A value's JSON type names exactly
one member, so there is never doubt over whose rules apply: a string meets the string
member's `maxLength`, a list the list member's `maxItems`. It also fixes the PHP type a
write stores, a `float` from a `Decimal` member or a `\DateTimeImmutable` from a `Date`.
And it keeps the projected schema exact, as [below](#the-generated-schema).

Presence, visibility and hooks belong on the union, not on a member. A member declaring
`required()`, `nullable()`, `readOnly()`, `hidden()`, `writeOnly()`, `on()` or one of the
four hooks throws a `LogicException` when the union is built.

### Reads and writes

A write picks the member by the value's JSON type. That member casts it, so `3` sent to a
union with a `Decimal` member stores `3.0`, and a `Date` member parses its string to a
`\DateTimeImmutable`. Inside an `Obj`, a `OneOf` variant or a list, a date member stores a
string instead, as a [nested date](#nested-dates-are-stored-as-strings) does. A cast that
fails, such as an impossible date, is a `422` at the union's pointer, or at the element's
inside a list member (`/data/attributes/value/2`).

A value no member takes is a type mismatch: `{}` for a union with no object member, `1.5`
for one whose number member is an `Integer`, or `null` for one that isn't nullable.
`ValueTypes::mismatchesIn()` returns each as one mismatch at its pointer whose `expected`
joins the union's types with `|` (`string|number|boolean|array`), and `expectedTypes()`
lists them. A value of a member's type then meets that member's own constraints at the
same pointer, and a list member's elements at their own.

A read picks the member by the stored value's PHP type. A string goes to the string
member, as does a `\DateTimeInterface` when the string member is a date. An `int` or
`float` goes to the number member, a `bool` to the boolean member and a list to the list
member. A backed enum goes by its value. That member renders the value, so a `Decimal`
member renders a stored `3` as `3.0` and a `Date` member formats its instant. A stored value
no member takes renders unchanged, as a `OneOf` value naming no variant does. That keeps a
response from failing over one odd row, but the value won't match the schema, so keep what
you store within the members.

### Lists of unions

`ArrayList::make('values')->of(Union::of(Str::make(), Decimal::make()))` makes each element
of the list a string or a number. Each element is cast by its own member and checked at
`/data/attributes/values/<index>`. A list never holds `null` in place of an element, so the
element union can't be `nullable()`, and the list takes no `sorted()` and no `each()`, since
mixed types share no order and the members carry the per-element rules.

### In a data layer

Hydration needs nothing from an adapter: the union casts through its member itself. Rule
validation is the adapter's job, and core makes the value-dependent choice for it.
`UnionValues::in()` takes the resource's fields and the body's attributes and returns a
`UnionValue` for each place a member applies, at any depth: a union attribute, a union
inside an `Obj`, `Map` or `OneOf` variant, an open object's member field, each element of
a list of unions, and each element of a union's list member. Each one carries the
`pointer`, the `union`, the selected `member` and the `value`, so an adapter translates
that member's constraints and validates the value at that pointer, as it does for any
field. `UnionValues::of()` walks one value from a pointer you give it, for a pivot field
in a linkage member's `meta`. A `null`, and a value no member takes, isn't returned, since
presence and the type check report those.

A required union refuses an empty string or list but accepts `false` and `0`. Each
`UnionValue` says so in `nonEmpty`, set when the union is required in the write's context
and the selected member is its string or list member, and `isRefusedAsEmpty()` answers for
its value. So an adapter adds its own non-empty rule to the member's constraints when
`nonEmpty` is set. For the presence rule on the union itself, ask
`ValueTypes::requiredRefusesEmpty()` of every field: it is `true` for strings, lists and
objects and `false` for numbers, booleans, a `OneOf` and a `Union`, so a required union
only has to be present and not `null` there.

### The generated schema

A union projects one schema whose `type` lists its members' types, `null` included when it
is nullable, with the members' keywords that only constrain their own type merged in:
`format`, `minLength`, `maxLength` and `pattern` for the string member, `minimum`,
`maximum`, `exclusiveMinimum`, `exclusiveMaximum` and `multipleOf` for the number member,
and `minItems`, `maxItems`, `uniqueItems` and `items` for the list member. The example
above projects as

```json
{
  "type": ["string", "number", "boolean", "array", "null"],
  "maxLength": 200,
  "items": { "type": ["string", "number", "boolean"] }
}
```

JSON Schema applies each of those keywords only to a value of its type, so the merged
schema accepts exactly what the members accept. That is only true because each type has
one member. Two string members with different `maxLength`s would leave nothing to merge.

A keyword that isn't scoped to one type, such as `enum`, `const`, a hoisted enum's `$ref`
or a member's own description, would constrain every member's values. When any member
carries one, the union projects a `oneOf` of the members' schemas instead, with `null` as
its own branch:

```php
Union::make('threshold')->of(Str::make()->in(['auto', 'off']), Integer::make()->min(0))->nullable();
```

```json
{
  "oneOf": [
    { "type": "string", "enum": ["auto", "off"] },
    { "type": "integer", "minimum": 0 },
    { "type": "null" }
  ]
}
```

The branches are exclusive because their types are. A required union refuses an empty
string or list, not `false` or `0`, so the schema carries `minLength: 1` and `minItems: 1`
for its string and list members only. The union's own description and example sit on the
schema itself. An operation example uses the union's example, or failing that the first
member's, in member order. The body-validation schema from `SchemaCompiler` is the same
schema, and a document holding a union declares the `UNIONS` contract tier in its
[`x-generator`](openapi.md#the-generator-contract-x-generator) stamp.

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

The value always renders as a JSON object. An empty array renders `{}`, and a
list-shaped array renders with its indexes as keys (`{"0": "a"}`), so a column
defaulting to `[]` still matches the field's `type: object`. A `null` stays `null`.

### A JSON array is not an object

`ArrayHash`, `Map`, `Obj` and `OneOf` all expect a JSON object on write. A non-empty
JSON array sent for one (`"preferences": [1, 2]`) is refused with a `422`
`ATTRIBUTE_VALUE_INVALID` whose `detail` ends `expected an object, got a list`. The
pointer names the field, so a list at the top level points at
`/data/attributes/preferences`, and a list for an `ArrayHash` child of an `Obj`
points at `/data/attributes/address/tags`. A `null` for a nullable field, and a
field left out of the body, are unaffected. A pivot field of one of these types
refuses a list the same way, through `castWireValue()`. A `fillUsing()` or
`deserializeUsing()` hook replaces the cast, so the adapters' type check refuses the
list instead, with a `VALIDATION_FAILED` at the same pointer.

An empty array is the exception. The request body is decoded into associative
arrays, so `[]` and `{}` both arrive as an empty PHP array and the field can't
tell which one the client sent. It accepts both. The optional
[`RequestValidationMiddleware`](schema-validation.md#wiring-it-into-the-middleware-chain)
doesn't catch `[]` either, because it checks the body against the JSON:API schema
and profile fragments only, not each attribute's type. To refuse `[]` you need a
check that sees the body with objects kept as objects: a schema compiled with
[`SchemaCompiler`](schema-validation.md#per-resource-schemas-schemacompiler) and
passed to `validateRequest()` (it types `ArrayHash` and `Map` as `object`), or an
adapter's own object-preserving validation.

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

## Subclassing and finality

Most concrete field types are `final`. The four intermediates that are **not** —
`Str`, `DateTime`, `BelongsTo` and `HasMany` — are non-final *by design*, because
each already has a shipped subclass that extends it: `Str` ← `Email`/`Url`/`Uuid`/
`Slug`/`Ip`, `DateTime` ← `Date`/`Time`, `BelongsTo` ← `HasOne`, and `HasMany` ←
`BelongsToMany`. The current finality set is therefore deliberate, not an
oversight. Dropping `final` from one of the remaining leaf types later to allow a
custom subclass is a **non-breaking** change (it only widens what callers may do),
so the surface can grow toward extensibility without a breaking release.

## Next

- [Fields](fields.md) — the shared fluent surface every type above inherits
  (naming, visibility, read-only contexts, constraint shortcuts, the four hooks).
- [Ids](ids.md) — the `Id` field and the id lifecycle (it is special-cased into
  the top-level `id`, not an attribute).
- [Relations](relations.md) — the relationship field types and the relation DSL.
- [Validation](constraints.md) — what the constraint metadata each type adds
  actually does, and the create/update context model.
