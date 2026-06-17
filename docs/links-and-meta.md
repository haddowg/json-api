# Links and meta

This page is about enriching a document beyond the field DSL: attaching free-form
`meta`, and setting `links` at the document, resource, relationship and error
levels. The [field](fields.md) and [relation](relations.md) DSLs already emit the
links and attributes the spec expects by convention — reach here when you need to
add something they don't cover.

## meta is free-form, and everywhere

`meta` is a non-standard, free-form `array<string, mixed>` the spec permits at
every level of a document: the top-level document, each resource object, each
relationship object, each link object, each error object, and the `jsonapi`
object. The library treats it uniformly: whatever you supply is serialized
as-is, and an **empty array is omitted** — you never get a `"meta": {}` you didn't
ask for.

Everything else on this page (`links`) is structured, but `meta` is the escape
hatch when the structured members don't have a slot for what you want to say.

## Document-level: withMeta / withLinks / withJsonApi

Every response value object extends `AbstractResponse`, so they all carry the
same three document-level withers. Each is immutable — it clones and returns a
new response (the [responses](responses.md) convention):

```php
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Schema\Link\Link;

$response = DataResponse::fromPage($page, $serializer)
    ->withMeta(['totalPlays' => 41_204])
    ->withLinks(DocumentLinks::withBaseUri(
        'https://music.example',
        self: new Link('/charts/top-100'),
    ))
    ->withJsonApi(new JsonApiObject(meta: ['poweredBy' => 'music-catalog']));
```

| Wither | Sets | Type |
| --- | --- | --- |
| `withMeta(array $meta)` | top-level `meta` | `array<string, mixed>` |
| `withLinks(?DocumentLinks $links)` | top-level `links` | `DocumentLinks` or `null` to clear |
| `withJsonApi(?JsonApiObject $jsonApi)` | the `jsonapi` member | `JsonApiObject` or `null` |

When you want a response whose *only* purpose is to carry meta — no primary
`data` — use `MetaResponse::fromMeta()`:

```php
use haddowg\JsonApi\Response\MetaResponse;

return MetaResponse::fromMeta(['queued' => true, 'jobId' => 'reindex-7']);
```

A `MetaResponse` still accepts `withLinks()`/`withJsonApi()` like any other
response.

## Resource-level: getMeta / getLinks on a serializer

Resource-object `meta` and `links` come from the two serializer hooks. On an
[`AbstractResource`](serializers.md) both default to "nothing" — `getMeta()`
returns `[]` and `getLinks()` returns `null` — so a resource emits no custom
`meta`, and only the by-convention `self` link (described
[below](#auto-emitted-links-you-dont-set-by-hand)) unless you override them. A bare
`AbstractSerializer` subclass leaves both methods abstract (they come from
`SerializerInterface`, and `AbstractSerializer` only supplies the
`TransformerTrait` helpers), so it must implement them itself — which is exactly
why the [`ChartSerializer`](../examples/music-catalog/src/Serializer/ChartSerializer.php)
writes the no-op defaults out by hand:

```php
// ChartSerializer.php — the two hooks, opted out
public function getMeta(mixed $object, JsonApiRequestInterface $request): array
{
    return [];
}

public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
{
    return null;
}
```

Override them to enrich a resource. Both are request-aware, so the meta or links
you emit can depend on the incoming request:

```php
public function getMeta(mixed $object, JsonApiRequestInterface $request): array
{
    \assert($object instanceof Album);

    return ['ratingCount' => $object->ratingCount];
}

public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
{
    \assert($object instanceof Album);

    return ResourceLinks::withBaseUri(
        'https://music.example',
        self: new Link('/albums/' . $object->id),
        links: ['sleeve' => new Link('/albums/' . $object->id . '/sleeve')],
    );
}
```

The conventional resource `self` link, and the `self`/`related` relationship
links, are emitted for you (see [below](#auto-emitted-links-you-dont-set-by-hand)
and [relations](relations.md)). You only reach for `getLinks()` when you want a
`self` link the library can't derive, or an extra custom relation alongside it — a
`self` you set here wins over the convention.

## Link forms

A link serializes one of two ways, and the library picks for you based on whether
it carries meta:

- **A bare URL string** — a [`Link`](../src/Schema/Link/Link.php)
  with no `meta` renders as a plain string (`"https://music.example/charts/top-100"`).
- **A link object** — the moment a `Link` carries `meta`, or you use the richer
  `LinkObject`, it renders as an object with an `href` member.

`Link` is the base: an `href` plus optional `meta`.

```php
new Link('/charts/top-100');                          // → bare string
new Link('/charts/top-100', meta: ['period' => '2026-W24']);  // → { "href": …, "meta": … }
```

`LinkObject` adds the full JSON:API 1.1 link-object vocabulary. Every string
member is optional and omitted when empty:

| Member | Meaning |
| --- | --- |
| `href` | the URL (required, first constructor arg) |
| `rel` | the link's relation type |
| `title` | a human-readable label |
| `type` | the media type of the target |
| `hreflang` | the target's language |
| `meta` | free-form meta on the link itself |
| `describedby` | a `Link` to a description (e.g. a schema) of the target |

```php
use haddowg\JsonApi\Schema\Link\LinkObject;

new LinkObject(
    '/albums/42/sleeve',
    rel: 'describedby',
    title: 'Album artwork',
    type: 'image/jpeg',
);
```

## Link containers and the baseUri prepend

Links are never set loose — they live in a keyed, construct-only container, one
per level. Each filters out `null` entries (so an absent relation is simply not
present) and prepends a shared `baseUri` to every `href` at render time:

| Container | Level | Reserved keys |
| --- | --- | --- |
| `DocumentLinks` | top-level document | `self`, `related`, `first`, `prev`, `next`, `last`, `profile` |
| `ResourceLinks` | resource object | `self` |
| `RelationshipLinks` | relationship object | `self`, `related` |
| `ErrorLinks` | error object | `about`, `type` |

`DocumentLinks`, `ResourceLinks` and `RelationshipLinks` each take the reserved
relations as named constructor arguments, plus an arbitrary `links` map for the
custom relations the spec also permits (`ErrorLinks` is the exception — it takes
only `about` and a list of `types`, with no custom-`links` map):

```php
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Schema\Link\Link;

DocumentLinks::withBaseUri(
    'https://music.example',
    self: new Link('/playlists'),
    links: ['feed' => new Link('/playlists.atom')],
);
```

The `baseUri` prepend lets you store host-relative `href`s and resolve them
against one base. `new Link('/playlists')` inside a container built with
`withBaseUri('https://music.example')` renders as
`https://music.example/playlists`. Each container offers three constructors:

- `new DocumentLinks($baseUri, …)` — the bare constructor, `baseUri` defaults to `''`.
- `DocumentLinks::withBaseUri($baseUri, …)` — names the base explicitly.
- `DocumentLinks::withoutBaseUri(…)` — for fully-qualified `href`s, no prepend.

A `baseUri` of `''` (the default) prepends nothing, so an already-absolute `href`
passes through untouched.

### Auto-emitted links you don't set by hand

Several families of `links` are populated for you, so you rarely construct a
`DocumentLinks`, `ResourceLinks` or `RelationshipLinks` directly. All are
spec-recommended (SHOULD) and on by default:

- **Resource `self`** — every resource object carries
  `links.self` = `{baseUri}/{uriType}/{id}`, the URL to fetch that resource. The
  path segment is the resource's `uriType` (which defaults to its JSON:API
  `type`), so a resource whose type is `book` but lives at `/books` links
  correctly. It is omitted for a resource with an empty id (a not-yet-persisted
  resource has no self), and a `getLinks()` `self` you set by hand wins over it.
  An `AbstractResource` opts out by overriding `emitsSelfLink()` to return
  `false`:

  ```php
  // No by-convention resource self link for this type.
  public function emitsSelfLink(): bool
  {
      return false;
  }
  ```

- **Top-level document `self`** — every data/resource document (a single
  resource, a collection, a related or relationship document, a meta document —
  but not an error document) carries a top-level `links.self` = the URI that
  produced it (`{server.baseUri}{request.path}`, including the query string on a
  filtered or sorted request). A paginated collection's per-page `self` (and a
  `self` you set with `withLinks()`) takes precedence.

- **Pagination** — `first`/`prev`/`next`/`last` (and the per-page `self`) are
  derived from the `Pagination\Page` when you build a collection response with
  `DataResponse::fromPage()`. See [pagination](pagination.md).

- **Relationship `self`/`related`** — emitted by convention for every declared
  relation. See [relations](relations.md).

The `DocumentLinks` pagination parameters remain available for the rare case of a
hand-built, non-paginated document, but `fromPage()` is the common path.

## Server-level defaults: the jsonapi object

When a response doesn't set its own `jsonapi` member, the library builds one from
two server-level defaults, set once at configuration time:

- `Server::withVersion(string $version)` — the `jsonapi.version`. Defaults to the
  spec version the library implements (`1.1`); set it to `''` to omit the version
  member.
- `Server::withDefaultMeta(array $meta)` — meta folded into the `jsonapi` object
  on every response.

```php
$server = Server::make()
    ->withVersion('1.1')
    ->withDefaultMeta(['poweredBy' => 'music-catalog']);
```

A response's own `withJsonApi()` takes precedence over these defaults for that
response. See [server](server.md) for the full configuration surface.

## Where profiles fit

A profile is the structured way to stamp meta onto *every* document a server
emits, rather than per response. The worked
[`TimestampProfile`](../examples/music-catalog/src/Profile/TimestampProfile.php)
adds a top-level `meta.generatedAt` in its `finalizeDocument()` hook, and the
applied profile URI is echoed in `jsonapi.profile` automatically. Reach for a
profile over `withMeta()` when the enrichment is cross-cutting and
negotiation-driven; reach for `withMeta()` when it's specific to one response.
Profiles are covered in [profiles](profiles.md).

## Error-level links and meta

Error objects carry their own `meta` and an `ErrorLinks` container (`about` plus
one or more `type` links). You set these on the exception/error rather than on a
response wither — see [errors and exceptions](errors-and-exceptions.md) for the
error-document shape.

## Next / See also

- [serializers](serializers.md) — the `getMeta()`/`getLinks()` hooks in context.
- [relations](relations.md) — the auto-emitted `self`/`related` relationship links.
- [pagination](pagination.md) — the auto-emitted `first`/`prev`/`next`/`last` links.
- [profiles](profiles.md) — cross-cutting meta via `finalizeDocument()`.
- [responses](responses.md) — the response value objects these withers live on.
