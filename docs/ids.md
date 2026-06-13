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

The `Id` field carries three format shortcuts. Each appends the matching
constraint, used to validate a client-supplied id on create:

| Helper | Constraint added | Use |
|---|---|---|
| `uuid(?int $version = null)` | RFC 4122 UUID format (optionally pinned to a version) | UUID ids |
| `numeric()` | pattern `^[0-9]+$` | digit-only ids |
| `pattern(string $regex)` | the regex you pass | any custom format |

[`PlaylistResource`](../examples/music-catalog/src/Resource/PlaylistResource.php)
declares a UUID id:

```php
public function fields(): array
{
    return [
        // A client-generated UUID id: the resource opts in below so a POST may
        // carry its own `id` (a default resource rejects one).
        Id::make()->uuid(),
        // …
    ];
}
```

These format constraints only matter when a client supplies the id. They are part
of the broader field constraint vocabulary — see [fields](fields.md).

## Server-side generation is the default

When no `id` is supplied on a `POST`, the server generates one. The default
`generateId()` returns an RFC 4122 v4 UUID; override it for any other scheme:

```php
protected function generateId(): string
{
    // default implementation returns a v4 UUID; override to mint your own.
}
```

A create with no `id` member is given a generated id and rendered with `201`:

```php
$response = $this->post('/albums', [
    'data' => [
        'type' => 'albums',
        'attributes' => ['title' => 'Hail to the Thief'],
    ],
]);
// 201, response data carries a non-empty server-generated `id`.
```

This is the witness in [`IdsTest`](../examples/music-catalog/tests/IdsTest.php)
(`aServerGeneratedIdIsAssignedWhenNoneIsSupplied`).

## Client-generated ids

By default a resource **rejects** a client-supplied id — the spec lets a server
do so. `acceptsClientGeneratedId()` returns `false`, and supplying an `id` on a
create yields a `403`
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

To honour client ids, override the opt-in — that is the whole difference between
`AlbumResource` and `PlaylistResource`:

```php
/**
 * Accept a client-supplied UUID id on create (the default is to reject one
 * with ClientGeneratedIdNotSupported).
 */
protected function acceptsClientGeneratedId(): bool
{
    return true;
}
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

### Validating a client-generated id

Client-id handling runs through one hook, `validateClientGeneratedId(string $id,
JsonApiRequestInterface $request)`, called before the id is applied. Beyond the
default not-supported rejection, this is where the two remaining client-id
exceptions belong:

| Exception | Status | Code | When |
|---|---|---|---|
| `ClientGeneratedIdNotSupported` | `403` | `CLIENT_GENERATED_ID_NOT_SUPPORTED` | a client id was supplied but the resource does not accept one |
| `ClientGeneratedIdRequired` | `403` | `CLIENT_GENERATED_ID_REQUIRED` | a client id is mandatory but none was supplied |
| `ClientGeneratedIdAlreadyExists` | `409` | `CLIENT_GENERATED_ID_ALREADY_EXISTS` | the supplied id collides with an existing resource |

All three carry an `ErrorSource` pointer of `/data/id`. The format helpers above
catch a *malformed* client id; these exceptions cover *policy* (not supported,
required, already taken). For how errors are shaped and rendered, see
[errors](errors-and-exceptions.md). For the hydrator side of the create lifecycle — `generateId()`,
`setId()`, `validateClientGeneratedId()` as the hooks a custom hydrator
implements — see [hydrators](hydrators.md).

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
