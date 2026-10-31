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

## Declared links

A link that belongs to every resource of a type, and that clients should find documented,
is better declared than built in `getLinks()`. Declare it in `links()`, next to
`fields()`, and the server renders it while the [OpenAPI document](openapi.md#links-and-describedby)
describes it by name. A file whose bytes are uploaded to a signed URL is the usual case:
the URL, the method and headers to send, and when the URL expires belong in the file's
`links`, not its attributes.

```php
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Link\DeclaredLink;

final class FileResource extends AbstractResource
{
    public static string $type = 'files';

    public function fields(): array { /* … */ }

    public function links(): array
    {
        return [
            DeclaredLink::make('upload')
                ->describedAs('Where to PUT the file\'s bytes, until it is uploaded.')
                ->meta(
                    Str::make('method')->in(['PUT'])->guaranteed()
                        ->describedAs('The HTTP method to upload with.')->example('PUT'),
                    ArrayHash::make('headers')->guaranteed()
                        ->describedAs('Headers the upload must send.')->example(['Content-Type' => 'image/png']),
                    DateTime::make('expiresAt')->guaranteed()
                        ->describedAs('When the URL stops working.')->example('2026-10-26T12:00:00+00:00'),
                )
                ->linkUsing(function (mixed $file, JsonApiRequestInterface $request): ?array {
                    \assert($file instanceof File);
                    if ($file->isUploaded() || !$this->canUpload($file, $request)) {
                        return null;
                    }
                    $signed = $this->uploads->sign($file);

                    return [
                        'href' => $signed->url,
                        'meta' => ['method' => 'PUT', 'headers' => $signed->headers, 'expiresAt' => $signed->expiresAt],
                    ];
                })
                ->example('https://uploads.example.com/f-1?signature=abc'),
            DeclaredLink::make('download')
                ->hrefUsing(fn(mixed $file, JsonApiRequestInterface $request): ?string => $file instanceof File && $file->isUploaded()
                    ? '/files/' . $file->id . '/content'
                    : null)
                ->example('https://api.example.com/files/f-1/content'),
        ];
    }
}
```

```json
"links": {
  "upload": {
    "href": "https://uploads.example.com/f-1?signature=…",
    "meta": {
      "method": "PUT",
      "headers": { "Content-Type": "image/png" },
      "expiresAt": "2026-10-26T12:00:00+00:00"
    }
  },
  "self": { "href": "https://api.example.com/files/f-1" }
}
```

`DeclaredLink::make()` returns a builder, the way `Str::make()` does, and `links()` may
return builders or built links:

| Method | Does |
| --- | --- |
| `hrefUsing(fn($model, $request): ?string)` | builds the href for one resource |
| `metaUsing(fn($model, $request): array)` | returns the meta values, keyed by member name; runs only when there is an href |
| `linkUsing(fn($model, $request): string\|array\|null)` | one call for both: `null`, the href, or `['href' => …, 'meta' => […]]` |
| `meta(...$fields)` | declares the `meta` members |
| `describedAs()` / `example()` | the OpenAPI description and example `href` |
| `rel()`, `title()`, `type()`, `hreflang()`, `describedBy()` | static link-object members; `type` is the target's media type |

An href that resolves to `null`, or to an empty string, leaves the link out of that
resource's `links`. That is how a link is hidden per viewer or per state: return `null`
when the caller may not use it, or when it doesn't apply yet.

Each meta member is a field, so it brings its schema, description and example with it,
and its value renders the way that field renders an attribute. The member reads its value
from the array the closure returned as it would read a model: a `DateTime` is formatted
and follows the server's [output zone](server.md), a declared member missing from the
array renders `null`, and a key no member declares is dropped. `hidden()` hides a member,
and `hidden(when:)` hides it per request and model. A relation or an `Id` can't be a meta
member.

A link with no meta members and no static member renders as a bare URL string. Declaring
any of them makes it a link object on every response, even one where every meta member is
hidden, so its shape doesn't depend on the data. A relative href gets the server's base
URI, as the built links do; the server's [link-object setting](#built-links-are-link-objects)
doesn't change a declared link's shape.

Declared links merge with the resource's other links. When two supply the same name, the
first of these wins:

1. a link `getLinks()` returns;
2. a declared link;
3. a contributed link, such as a custom action's link;
4. the by-convention `self`.

`self` can't be declared; use `getLinks()` or `emitsSelfLink()` for that. Sparse fieldsets
select attributes and relationships, never links, so a `fields[files]` parameter leaves
declared links alone.

### Action links

An adapter can render one of a type's resource-scope custom actions as a member of each
resource's `links`, keyed by the action's path. Declaring an `ActionLink` in `links()`
does that for the action at the path you name, and adds two things the action can't say on
its own: a condition the model and request must meet, and the description the OpenAPI
document gives the link. A file that can be completed only while its upload is pending,
and only by the person uploading it, is the usual case:

```php
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Link\ActionLink;

public function links(): array
{
    return [
        ActionLink::for('complete')
            ->when(fn(StoredFile $file, JsonApiRequestInterface $request): bool => $file->status === FileStatus::PendingUpload
                && $file->uploaderId === $this->currentUser($request)?->id)
            ->describedAs('Present while the file is PENDING_UPLOAD, for its uploader.'),
    ];
}
```

The adapter still builds the link from the action: its href on the server's link base,
and the `describedby` that names the action's operation. It checks the action's security
first and calls `when()` only when that passes, so the link renders when both do. Keep the
state in `when()` rather than in the action's security: the security check runs again when
the action is invoked, and a state test there would turn the handler's own error into a
plain `403`.

| Method | Does |
| --- | --- |
| `ActionLink::for($path)` | names a resource-scope action on the resource's own type |
| `when(fn($model, $request): bool)` | renders the link only when it returns `true`; optional. The model parameter may be typed as your model class |
| `describedAs()` | replaces the generic action-link description in `<Type>ResourceLinks` |

Declaring an `ActionLink` implies the link, so the action doesn't need its adapter's own
"render as a link" switch. When it has one too, the `ActionLink`'s condition and
description apply. The projection is otherwise unchanged: the link is an optional property
whose example's `describedby` points at the action's operation, and the condition itself
isn't described.

`allActionLinks()` returns the built action links, and `allLinks()` leaves them out. Two
action links for one path, or a declared link named like an action link, throw a
`\LogicException` naming the resource class. A resource served on several servers may name
an action only some of them expose: a server without it neither renders nor projects the
link. An adapter fails the build when no server serving the type has the action, so a typo
still gets caught
([ADR 0178](adr/0178-a-resource-declares-its-named-links-and-the-projection-describes-them.md)).

## Link forms

The links the server builds for you (the [auto-emitted ones below](#auto-emitted-links-you-dont-set-by-hand))
render as link objects by default; see [built links are link objects](#built-links-are-link-objects).
A link you build yourself serializes one of two ways, and the library picks for you based on
whether it carries meta:

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
| `hreflang` | the target's language (the OpenAPI schema also allows a list) |
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

An **already-absolute `href`** — a scheme-qualified (`https://…`) or
protocol-relative (`//host/…`) URL — passes through **untouched**, never prefixed,
whatever the base. So a fully-qualified link (a common shape for an error
`about`/`type` documentation URL) is safe even when the container carries a base.

A **path href without a leading slash** (`uploads/1`, a shape a stored path often
comes back in) is joined to a non-empty base with one slash, so it renders as
`https://music.example/uploads/1` rather than gluing the two together. An href that
starts with `/`, `?`, `#` or a URI-template `{`, and any href rendered with an empty
base, is concatenated exactly as written.

For the auto-emitted links below, the base your container is given is resolved by
the response layer: when [`Server::withBaseUri()`](server.md#base-uri-and-the-request-origin)
is empty (the default), it is the **request origin** (`<scheme>://<authority>`),
so those links render request-absolute; when it is configured, it is that fixed
canonical host (trailing-slash trimmed). A container you build by hand with an
explicit `withBaseUri(...)` keeps the base you gave it.

### Auto-emitted links you don't set by hand

Several families of `links` are populated for you, so you rarely construct a
`DocumentLinks`, `ResourceLinks` or `RelationshipLinks` directly. All are
spec-recommended (SHOULD) and on by default:

- **Resource `self`** — every resource object carries
  `links.self` = `{base}/{uriType}/{id}`, the URL to fetch that resource, where
  `{base}` is the [configured base URI or the request origin](server.md#base-uri-and-the-request-origin). The
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
  produced it (`{base}{request.path}`, including the query string on a filtered or
  sorted request, where `{base}` is the [configured base URI or the request
  origin](server.md#base-uri-and-the-request-origin)). A paginated collection's
  per-page `self` (and a `self` you set with `withLinks()`) takes precedence.

- **Pagination** — `first`/`prev`/`next`/`last` (and the per-page `self`) are
  derived from the `Pagination\Page` when you build a collection response with
  `DataResponse::fromPage()`. See [pagination](pagination.md).

- **Relationship `self`/`related`** — emitted by convention for every declared
  relation. See [relations](relations.md).

The `DocumentLinks` pagination parameters remain available for the rare case of a
hand-built, non-paginated document, but `fromPage()` is the common path.

- **Contributed links.** A framework integration can add resource links your
  `getLinks()` doesn't know about through a `ResourceLinkContributorInterface`,
  such as a custom action's link. A key your `getLinks()` already sets, or a
  [declared link](#declared-links) of the same name, wins.

## Built links are link objects

Every link in the list above renders as a JSON:API 1.1 link object rather than a
bare string:

```json
"links": {
  "self": {
    "href": "https://music.example/albums/1",
    "describedby": "/api/docs.json#/paths/~1albums~1%7Bid%7D/get"
  }
}
```

A link you supply yourself, through `getLinks()`, a relation's `setLinks()` or a
response's `withLinks()`, keeps the shape you gave it, so a plain `Link` there is
still a bare string. The `201` `Location` header your handler sets stays a plain URI;
it never was a document link.

JSON:API requires a client to accept either form, so a conforming client reads both.
Code that read a built link as a string (`$body['data']['links']['self']` used as a
URL, say) now needs its `href`. To keep strings, opt the server out:

```php
$server = Server::make()->withLinkObjects(false);
```

The opt-out covers the same built links and nothing else.

### describedby: the OpenAPI operation behind a link

Give the server a `DescribedByResolverInterface` and each built link object gains a
`describedby` member: a URI-reference to the operation in your served
[OpenAPI document](openapi.md) that describes the link's target. Core ships
`SpecDescribedByResolver`, which pairs the document's URL with the set of operations
the document carries:

```php
use haddowg\JsonApi\OpenApi\SpecDescribedByResolver;

$server = $server->withDescribedByResolver(
    SpecDescribedByResolver::fromMetadata('/api/docs.json', $metadata),
);
```

The reference is the document URL plus a JSON Pointer fragment naming the path and
method: `/api/docs.json#/paths/~1albums~1%7Bid%7D/get` (`/` escapes to `~1`, `~` to
`~0`, and `{`/`}` are percent-encoded). The path is the projector's own path key,
relative to the document's server URL, so a mount prefix lives in the document URL,
never in the fragment. A host-relative document URL gives host-relative references; an
absolute one is used as given. An operation the document doesn't carry gets no
`describedby`, so a link never points at a fragment that isn't there.

Each link names the operation its target is:

| Link | `describedby` points at |
| --- | --- |
| resource `self` | `GET /{type}/{id}` |
| relationship `self`, and a relationship's pagination links | `GET /{type}/{id}/relationships/{rel}` |
| relationship `related` | `GET /{type}/{id}/{rel}` |
| top-level `self` and pagination links | the operation that produced the document |
| a contributed `OperationLink` | the operation it names (an action link names the action's method and path) |

A document also gains a top-level `links.describedby` pointing at the operation that
produced it. `Server::dispatch()` and the PSR-15 `handle()` path both record that
operation on the response they return, so the two render identically; a handler can
name a different one with `withOperationReference()`. Error documents get none: they
describe the failure, not the operation.

An Atomic Operations result document is described by the atomic endpoint's `POST`
when the `AtomicOperationsOperation` carries the endpoint's path, the same path its
`AtomicOperationsMetadataInterface::path()` returns (`/operations`, say). The runtime
can't work that path out on its own, so the integration that builds the operation
passes it as the fourth constructor argument; without it, or when the document has no
such path, the result gets no top-level `describedby`. The resource objects inside the
results follow the server's link settings either way.

An author's top-level `describedby` always beats the per-operation one. A
`describedby` set with `withDescribedby()`, or one inside the container given to
`withLinks()`, is rendered as given and the operation's pointer is dropped. So an
integration that used `withDescribedby()` to point every document at the whole
OpenAPI document will hide the per-operation pointer; remove that call once a resolver
is configured.

An integration that caches the document's operations at warmup can build the map with
`SpecDescribedByResolver::operationsOf($document)` (it takes the `OpenApi` document or
its `Paths`) and construct the resolver per request with
`new SpecDescribedByResolver($url, $operations)`; `operations()` returns the map a
resolver holds.

A contributor describes its own links by returning an `OperationLink`, which names the
operation alongside the `href`:

```php
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\OperationReference;
use haddowg\JsonApi\Schema\Link\OperationLink;

return ['publish' => new OperationLink(
    $url,
    OperationReference::action('albums', 'publish', ActionScope::Resource, 'POST'),
)];
```

With no resolver (the default), links are still link objects, just without `describedby`
([ADR 0158](adr/0158-built-links-are-link-objects-described-by-their-openapi-operation.md)).

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
