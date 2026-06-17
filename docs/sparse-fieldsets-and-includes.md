# Sparse fieldsets and compound documents

Two query parameters shape what a response carries: `fields[TYPE]` narrows *which
members* of a resource render, and `include` pulls *related resources* into the
same document. This page covers both — how the engine applies them, how to exempt
a member from narrowing, how default includes work, and how to read the parsed
values off an operation.

If you are new here, start with the [getting started](getting-started.md) tour;
this page assumes you already have a resource rendering.

## Sparse fieldsets: `fields[TYPE]`

A `fields[type]=a,b` parameter restricts the `type` resource to the named members
— both attributes and relationships. Anything not listed is dropped. The engine
applies this efficiently: it only invokes the member callables that survive the
filter, so a narrowed payload does no work computing values you discarded.

The `id` is always present. It is structural identity, not an attribute member,
so it is exempt from narrowing.

### Worked example: narrowing an album

[`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php) declares
`title`, `releasedAt`, `releaseInfo`, `explicit`, the date fields, and the
`artist` / `tracks` relationships. Ask for just the title:

```
GET /albums/1?fields[albums]=title
```

```json
{
  "data": {
    "type": "albums",
    "id": "1",
    "attributes": { "title": "..." }
  }
}
```

Only `title` survives in `attributes`; the `id` and `type` remain because they are
structural. A fieldset can name a relationship too — `fields[albums]=title,artist`
keeps the `title` attribute and the `artist` relationship and drops everything else,
including `tracks`. See
[`SparseFieldsetsAndIncludesTest`](../examples/music-catalog/tests/SparseFieldsetsAndIncludesTest.php)
for the assertions that pin this behaviour.

### Exempting a member: `notSparseField()`

Sometimes a member must always render — say a checksum or a status flag a client
relies on. Call `notSparseField()` on the field and it is serialized regardless of
any `fields[type]` parameter:

```php
use haddowg\JsonApi\Resource\Field\Str;

Str::make('status')->notSparseField();
```

`isSparseField()` on the field reports whether it participates; a field that opts
out is excluded from the narrowing pass entirely. This is the one member-level
override — every other field follows the fieldset. See
[fields](fields.md) for the rest of the shared field surface.

## Compound documents: `include`

`include` builds a *compound document*: the requested related resources are fetched
and placed in a top-level `included` array alongside the primary `data`. Paths are
comma-separated and may be nested with a dot — `?include=artist,tracks.playlists`
includes each album's artist, its tracks, and each track's playlists.

### Worked example: an album with its artist and tracks

```
GET /albums/1?include=artist,tracks
```

```json
{
  "data": {
    "type": "albums",
    "id": "1",
    "relationships": {
      "artist": { "data": { "type": "artists", "id": "1" } },
      "tracks": { "data": [ { "type": "tracks", "id": "1" }, ... ] }
    }
  },
  "included": [
    { "type": "artists", "id": "1", "attributes": { ... } },
    { "type": "tracks", "id": "1", "attributes": { ... } },
    ...
  ]
}
```

The `data` member carries linkage (the `{type, id}` identifiers); the full related
resources live in `included`. Fieldsets compose with includes — adding
`fields[artists]=name` narrows the *included* artist's attributes to `name`,
exactly as it would the primary resource.

### Deduplication; primary takes precedence

`included` never carries the same `{type, id}` pair twice. Fetch a collection of
albums that share an artist with `?include=artist`, and that artist appears once.
A resource that is part of the primary `data` is also never duplicated into
`included` — the primary representation wins.

### Default-included relationships

A resource can include a relationship *by default*, applied only when the request
sends no `include`. There is no fluent "include by default" field method; this
lever lives on the serializer contract. Override
`getDefaultIncludedRelationships()` and return the relationship names:

```php
// AlbumResource
public function getDefaultIncludedRelationships(mixed $object): array
{
    return ['artist'];
}
```

With that override, `GET /albums/1` (no `?include`) emits the artist in
`included`. The moment a request sends *any* `include`, the default is suppressed
— `GET /albums/1?include=tracks` includes the tracks and *not* the artist. The
signature is `getDefaultIncludedRelationships(mixed $object): array`; it is passed
the domain object so the set can vary per record.

## How a relation participates in `included`

A relationship's linkage policy interacts with includes. By default a relation
renders its linkage (`data`) on every response. A relation marked
`dataOnlyWhenLoaded()` — as `tracks` is on `AlbumResource` — emits linkage only
when the related data is already loaded, to avoid forcing a fetch. When that
relation is *explicitly included*, the include wins: the resources are fetched and
both the linkage and the `included` entries appear. See
[relations](relations.md) for the full linkage and `links` policy.

## Constraining includes: the safeguards

By default every declared relationship is includable at every path, and nested
`?include` chains are unbounded. Three composable safeguards let you constrain that
— and guarantee a compound document always terminates (a mutual default-include
cycle would otherwise recurse forever).

### Per-relation opt-out: `cannotBeIncluded()`

Mark a relation non-includable on the field. A `?include` naming it (at any path)
is rejected with `400 InclusionNotAllowed`, and it is dropped from the
default-include cascade. Its linkage and its `self` / `related` links are
unaffected — only the compound expansion is suppressed.

```php
// AlbumResource::fields()
BelongsTo::make('internalNotes')->type('notes')->cannotBeIncluded(),
```

### Maximum include depth

Depth is the number of relationship hops from the primary resource:
`?include=artist` is depth 1, `?include=tracks.playlists` is depth 2,
`?include=tracks.playlists.owner` is depth 3. A cap of N allows depth ≤ N and
rejects deeper requests with `400 InclusionDepthExceeded`.

Set a server-wide default (core is unopinionated: unset, or any value `<= 0`, means
*unlimited*):

```php
$server = Server::make()->withMaxIncludeDepth(3);
```

A resource overrides the default for itself by implementing
`IncludeControlsInterface::maxIncludeDepth()` (an `AbstractResource` subclass just
overrides the method — it already implements the interface):

```php
// AlbumResource — cap includes rooted at an album at 2, beating the server default
public function maxIncludeDepth(): ?int
{
    return 2;
}
```

Resolution is `resource override ?? server default`, with `<= 0` normalised to
unlimited. Beyond the cap the compound *expansion* is silently dropped from the
default cascade (the linkage identifier is still emitted), so the cascade always
terminates; an over-deep *requested* path is the up-front `400`.

### Allowed include paths (root-scoped whitelist)

`cannotBeIncluded()` is all-or-nothing for a relation at its own resource. To allow
a relation directly yet forbid it as a *nested* path from a parent, declare the full
dotted paths permitted when this resource is the request's primary/root type, via
`IncludeControlsInterface::getAllowedIncludePaths()`:

```php
// UserResource — posts (and posts.author) are includable, but posts.comments is not
public function getAllowedIncludePaths(): ?array
{
    return ['posts.author'];
}
```

`GET /users/1?include=posts.comments` is now `400 InclusionNotAllowed` even though
`comments` is includable when a post is the root
(`GET /posts/1?include=comments` still succeeds). Listing a deep path **implies its
ancestors** — `['posts.author']` permits `posts` and `posts.author` without
enumerating every prefix, but not the sibling `posts.comments`. `null` (the default)
is unrestricted (today's behaviour); an empty list `[]` permits no includes at all.

The three safeguards compose: a path is permitted only if every hop's relation is
includable, it is within the effective max depth, and it is in the root's allowed
paths when one is set.

## Reading the parsed query

Inside a [handler](operations.md), the parsed query parameters hang off the
operation as a plain value object:

```php
$query = $operation->queryParameters();

$query->fields;    // array<string, list<string>> — keyed by type
$query->includes;  // list<string> — the requested include paths
```

`QueryParameters` is a readonly value object — the public properties *are* the
accessors, there are no getters. `fields` is keyed by resource type to its
requested member names; `includes` is the flat list of include paths (a nested
`tracks.playlists` arrives as the single string `tracks.playlists`). The
[in-memory handler](../examples/music-catalog/src/Handler/MusicCatalogHandler.php)
reads these off the operation when it builds a response. Malformed values are
tolerated and skipped during parsing — well-formedness of the request itself is
the negotiation layer's job, not this projection's.

That tolerance is **key-level** — a bad value or unknown member *inside* the
`fields` / `include` families. The families themselves are always recognized, so
they never trip the
[strict query-parameter validation](content-negotiation.md#strict-query-parameter-validation-on-by-default)
`Server` runs by default: an unrecognized query-parameter *family* (a misspelled
`?inclde=...`, an unregistered custom parameter) is a `400` `QueryParamUnrecognized`,
distinct from this key-level tolerance within a recognized family. (An `include`
*path* naming an unknown relationship is its own `400 InclusionUnrecognized`,
covered in [Spec errors](#spec-errors) below.)

## Spec errors

| Exception | Status | When |
| --- | --- | --- |
| `InclusionUnrecognized` | `400` | An `include` path names a relationship the endpoint does not recognize. The error's `source` is the `include` parameter and lists the offending paths. |
| `InclusionNotAllowed` | `400` | An `include` path names a relationship marked `cannotBeIncluded()`, or a path outside the root resource's `getAllowedIncludePaths()` whitelist. Lists the offending paths. |
| `InclusionDepthExceeded` | `400` | An `include` path is deeper than the effective maximum include depth. Lists the offending paths and the cap. |
| `InclusionUnsupported` | `400` | The endpoint does not support inclusion at all. |

Both render as JSON:API errors with `source.parameter` set to `include`. See
[errors and exceptions](errors-and-exceptions.md) for the propagation model and the
full exception catalogue.

## Next / see also

- [fields](fields.md) — the shared field surface, including `notSparseField()`.
- [relations](relations.md) — linkage policy and `dataOnlyWhenLoaded()`.
- [pagination](pagination.md) — windowing the primary collection and related to-many collections.
- [errors and exceptions](errors-and-exceptions.md) — `InclusionUnrecognized` / `InclusionNotAllowed` / `InclusionDepthExceeded` / `InclusionUnsupported` and the rest of the catalogue.
