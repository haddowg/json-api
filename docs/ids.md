# Resource identifiers and client-generated ids

Every JSON:API resource carries a top-level `id`. This page shows where that id
comes from on read, how to point it at a non-default source column or constrain
its format, and how to decide between server-generated and client-generated ids
on create.

## Id is usually implicit

You rarely declare an id at all. A resource that lists an `Id` field with no
source — `Id::make()` — reads the `id` property (or `getId()` accessor) of the
domain object and renders it as the resource's top-level `id`. That is the whole
common case, and it is what [`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php)
does:

```php
public function fields(): array
{
    return [
        Id::make(),
        Str::make('title')->required()->maxLength(200)->sortable(),
        // …
    ];
}
```

`GET /albums/2` then renders `"id": "2"` read straight from the seeded album.
There is no implicit fallback: omit `Id::make()` entirely and the resource has no
id field, so it renders an empty top-level `id` and applies no id on create. The
only default at play is that `Id::make()` — when you do declare it — defaults its
source to the `id` column. Declaring it is the habit that pays off the moment you
need any of the levers below.

## When to declare one

Reach for an explicit `Id` field in two situations:

- **The id lives in a non-default column.** Pass a name to `make()` and the id is
  read from that property instead of `id`: `Id::make('uuid')` renders the top-level
  `id` from the object's `uuid` property (or `getUuid()`).
- **You want a format rule.** A client-generated id (see below) should be
  constrained so a malformed client value is rejected before it reaches storage.

`Id` is special-cased: although it is declared alongside attribute fields, it is
rendered into the resource's top-level `id`, never into `attributes`, and it is
always serialized as a **string** — a numeric `2` becomes `"2"` on the wire.

## Format helpers for a client-generated id

The `Id` field carries four format shortcuts. Each appends the matching
constraint, used to validate a client-supplied id on create, **and** sets the
route `{id}` requirement so a malformed id in a URL is rejected at routing (a
`404`) before any handler runs:

| Helper | Constraint added | Route `{id}` pattern | Use |
|---|---|---|---|
| `uuid(?int $version = null)` | RFC 4122 UUID format (optionally pinned to a version) | the UUID regex | UUID ids |
| `ulid()` | ULID format (26-char Crockford base32, case-insensitive) | the ULID regex | ULID ids |
| `numeric()` | pattern `^[0-9]+$` | `[0-9]+` | digit-only ids |
| `pattern(string $regex)` | the regex you pass | `$regex` with any leading `^` / trailing `$` stripped | any custom format |

The constraint side keeps the anchored ECMA-262 form JSON Schema requires; the
route side is the **inner** regex a Symfony route requirement expects (Symfony
anchors it). One call governs both.

### Setting only the route pattern: `matchAs()`

To constrain the URL `{id}` without adding a create-id format constraint — for an
id that is server-generated but still has a known shape — call
`matchAs(string $pattern)`. It stores the inner route regex (no surrounding
`^…$`) read back via `routePattern()`; the framework integration applies it as the
`{id}` route requirement. The format shortcuts call `matchAs()` for you (a later
shortcut does not overwrite an explicit `matchAs()`).

[`PlaylistResource`](../examples/music-catalog/src/Resource/PlaylistResource.php)
declares a UUID id:

```php
public function fields(): array
{
    return [
        // A client-generated UUID id: allowClientId() opts in so a POST may carry
        // its own `id` (a default resource rejects one).
        Id::make()->uuid()->allowClientId(),
        // …
    ];
}
```

These format constraints only matter when a client supplies the id. They are part
of the broader field constraint vocabulary — see [fields](fields.md).

## Where a create's id comes from

Two orthogonal axes on the `Id` field decide a created resource's id. Both have a
default, so a plain `Id::make()` is the common case: a client id is rejected, and
the store assigns the id.

**Axis 1 — client-id acceptance** (default: **forbidden**):

| Call | A client `data.id` is… |
|---|---|
| *(default)* | rejected with `403` `ClientGeneratedIdNotSupported` |
| `allowClientId()` | optional — used when supplied (validated against the format), otherwise the Axis 2 fallback applies |
| `requireClientId()` | mandatory — its absence is `403` `ClientGeneratedIdRequired` |

Read with `allowsClientId()` / `requiresClientId()`.

**Axis 2 — the fallback when the client supplies none** (default:
**store-provided**):

| Call | When no client id is supplied… |
|---|---|
| *(default)* | core sets **nothing** — the persister/DB assigns the id (an auto-increment column, a database default) |
| `generated()` | core mints one from the declared format: `uuid()` → a v4 UUID, `ulid()` → a Crockford-base32 ULID |
| `generateUsing(fn)` | core mints one by calling your closure (it returns the storage key directly) |

`generated()` only works on a self-generating format — declare `uuid()` or `ulid()`
first. `generated()` on `numeric()`, `pattern()`, or no format is a configuration
error (a `\LogicException` raised when the field is declared). For anything else,
use `generateUsing()`.

### Store-provided is the default

With a plain `Id::make()` and no client id, core sets no id and the store assigns
one. This is the natural fit for an auto-increment integer key:

```php
// The resource just declares Id::make(); the entity's id column is a DB-assigned
// auto-increment.
$response = $this->post('/widgets', [
    'data' => ['type' => 'widgets', 'attributes' => ['name' => 'Sprocket']],
]);
// 201; response data carries the DB-assigned id, e.g. "id": "42".
```

The id is read back off the persisted entity for the `201` body and the `Location`
header, so a store-assigned id round-trips exactly like any other.

### App-generated ids

Call `generated()` (or `generateUsing()`) when the *application* should mint the id
rather than the database — typically a UUID or ULID primary key. `AlbumResource`
declares `Id::make()->uuid()->generated()`, so a `POST` with no `id` is given an
app-minted UUID:

```php
$response = $this->post('/albums', [
    'data' => [
        'type' => 'albums',
        'attributes' => ['title' => 'Hail to the Thief'],
    ],
]);
// 201, response data carries a non-empty app-generated UUID `id`.
```

This is the witness in [`IdsTest`](../examples/music-catalog/tests/IdsTest.php)
(`aServerGeneratedIdIsAssignedWhenNoneIsSupplied`). A `ulid()->generated()` field
mints a lexicographically-sortable ULID instead; `generateUsing(fn)` gives you full
control over the minted storage key.

## Client-generated ids

By default a resource **rejects** a client-supplied id — the spec lets a server
do so. Supplying an `id` on a create yields a `403`
[`ClientGeneratedIdNotSupported`](../examples/music-catalog/src/Resource/AlbumResource.php)
(pointer `/data/id`). Posting an id to `albums`, which does not opt in, is a
`403`:

```php
$response = $this->post('/albums', [
    'data' => [
        'type' => 'albums',
        'id' => '99999999-9999-4999-8999-999999999999',
        'attributes' => ['title' => 'Amnesiac'],
    ],
]);
// 403 ClientGeneratedIdNotSupported
```

To honour client ids, call `allowClientId()` on the `Id` field — that is the whole
difference between `AlbumResource` and `PlaylistResource`:

```php
// Accept a client-supplied UUID id on create; fall back to store-provided when
// none is given.
Id::make()->uuid()->allowClientId();
```

With the opt-in in place a `POST` that carries its own UUID is honoured verbatim:
the created resource is rendered at exactly that id and the `Location` header
points at the client-chosen value, not a server-minted one. The same id is then
readable directly:

```php
$response = $this->post('/playlists', [
    'data' => [
        'type' => 'playlists',
        'id' => 'a1a2a3a4-b1b2-4c3c-8d4d-e1e2e3e4e5e6',
        'attributes' => ['title' => 'Late Night', 'public' => true],
    ],
]);
// 201; Location: https://music.example/playlists/a1a2a3a4-b1b2-4c3c-8d4d-e1e2e3e4e5e6

$fetched = $this->get('/playlists/a1a2a3a4-b1b2-4c3c-8d4d-e1e2e3e4e5e6');
// 200, same id, readable at the client-chosen id.
```

To make a client id **mandatory** — a natural key the client owns, with no
server-side fallback — call `requireClientId()`: a create without an `id` is then a
`403` `ClientGeneratedIdRequired`.

### The client-id exceptions

| Exception | Status | Code | When |
|---|---|---|---|
| `ClientGeneratedIdNotSupported` | `403` | `CLIENT_GENERATED_ID_NOT_SUPPORTED` | a client id was supplied but the resource does not accept one (`allowClientId()` not set) |
| `ClientGeneratedIdRequired` | `403` | `CLIENT_GENERATED_ID_REQUIRED` | `requireClientId()` is set but no id was supplied |
| `ClientGeneratedIdAlreadyExists` | `409` | `CLIENT_GENERATED_ID_ALREADY_EXISTS` | the supplied id collides with an existing resource (raised by the data layer) |

All three carry an `ErrorSource` pointer of `/data/id`. The format helpers above
catch a *malformed* client id; these exceptions cover *policy* (not supported,
required, already taken). For how errors are shaped and rendered, see
[errors](errors-and-exceptions.md).

## Encoding: when the wire id differs from the storage key

Sometimes the id a client sees is a transform of the value the entity actually
stores — a binary UUID exposed as a string, an integer primary key obscured
behind a reversible codec (hashids), and so on. Attach an
`IdEncoderInterface` with `encodeUsing()` and the `Id` field becomes the **wire
form** of a distinct **storage key**:

```php
interface IdEncoderInterface
{
    public function encode(mixed $storageKey): string; // storage -> wire
    public function decode(string $wireId): mixed;      // wire -> storage; null when undecodable
}
```

```php
Id::make()->encodeUsing($myEncoder);
```

The entity always holds the storage key. Core drives the entity's own id
transform across both directions of the lifecycle:

- **On serialize** the stored key is `encode()`d, so every rendered `id` (the
  top-level member and any id-as-field) is the wire form.
- **On create with a client id** the wire id is `decode()`d to the storage key and
  *that* is set on the new entity — so a created entity holds the storage key,
  exactly like a read entity, and its rendered id round-trips. A well-formed id
  that `decode()` rejects (`null`) is a `422` `ResourceIdUndecodable` (pointer
  `/data/id`); the format constraint above already catches a *malformed* id before
  hydration, so `decode()` only ever runs on a well-formed value — the `null`
  branch is the safety net. An app-generated id (`generated()` / `generateUsing()`,
  no client id) is a storage key already and is set as-is, never decoded — it is the
  storage key's own wire form, not the encoder's input, so feeding it to `decode()`
  would reject every minted create. A store-provided id is never set by core at all.
  Update (`PATCH`) never sets the id, so it does not decode.

A type with **no** encoder behaves exactly as before: wire == storage, nothing is
transformed. The id-as-lookup-key transforms — decoding the route `{id}` before a
database find, and decoding linkage ids in relationship writes — live in the
framework integration's data layer, not in core, because they flow through the
provider/persister as wire strings; see the Symfony bundle's data-layer docs.

## `lid` is a separate concern

A request may reference a not-yet-created resource by a local id (`lid`) inside a
single document. That is resolved during request processing, not by the `Id`
field, and is covered in [concepts](concepts.md).

## `Id` vs the `Uuid` attribute field

Do not confuse the two. `Id` produces the resource's **top-level `id`**;
`Uuid::make('externalId')` is an ordinary **attribute** that happens to validate
UUID format (it is sugar for `Str::make('externalId')->uuid()`) and renders inside
`attributes`. `PlaylistResource` uses both: `Id::make()->uuid()` for the resource
identifier and `Uuid::make('externalId')->nullable()` for a separate UUID-valued
attribute. Choose `Id` for the identity of the resource and `Uuid` (or any
attribute field) for a UUID-shaped value that merely belongs to it.

## Next / See also

- [fields](fields.md) — the full field and constraint vocabulary the format
  helpers draw on.
- [hydrators](hydrators.md) — `generateId()`, `setId()` and
  `validateClientGeneratedId()` as create-lifecycle hooks.
- [errors](errors-and-exceptions.md) — how the client-id exceptions render as JSON:API errors.
- [concepts](concepts.md) — `lid` and request-time identity resolution.
