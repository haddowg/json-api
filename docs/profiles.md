# Profiles

A [JSON:API 1.1 profile](https://jsonapi.org/format/1.1/#profiles) is a named set
of document members and processing rules, reserved for implementors, that a server
*may* apply to a response. This page shows you how to implement a profile, register
it on a [`Server`](server.md), and have a response that applies it advertise the
profile to the client.

Profiles are **advisory**. A server applies the profiles it recognizes and ignores
any it does not, so a profile a client asks for but the server has not registered is
silently dropped rather than rejected. That is the defining contrast with
[extensions](content-negotiation.md#profiles-flow-through-extensions-can-fail), which require strict
client/server agreement (a request asking for an extension the server does not
support is rejected with a `400`, never silently ignored).

## The profile contract

A profile implements `Schema\Profile\ProfileInterface`:

```php
namespace haddowg\JsonApi\Schema\Profile;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

interface ProfileInterface
{
    public function uri(): string;

    /** @return list<string> */
    public function keywords(): array;

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function finalizeDocument(array $document, JsonApiRequestInterface $request): array;
}
```

- **`uri()`** is the profile's canonical URI. It is the value matched against the
  negotiated `profile` media-type parameter, advertised in top-level
  `links.profile`, and echoed in the response `Content-Type` `profile` parameter.
- **`keywords()`** lists the member, link-relation, and query-parameter names the
  profile reserves. It is for documentation and introspection (and future schema
  validation); it does **not** gate negotiation. A request that asks for a profile
  is honoured by URI alone — the reserved keywords are never inspected to decide
  whether the profile applies.
- **`finalizeDocument()`** is a finalisation hook, run **once** for the profile
  after the document body array has been assembled and before it is encoded. It
  receives the body and the active request and returns the (possibly augmented)
  body. Only profiles the server has applied are run.

### The convenience base

`Schema\Profile\AbstractProfile` defaults `keywords()` to `[]` and
`finalizeDocument()` to the identity, so a subclass need only implement `uri()` and
override the hooks it actually uses:

```php
abstract class AbstractProfile implements ProfileInterface
{
    public function keywords(): array
    {
        return [];
    }

    public function finalizeDocument(array $document, JsonApiRequestInterface $request): array
    {
        return $document;
    }
}
```

The contract stays implementable by composition — the base is an ergonomic
shortcut, not a requirement.

## A custom profile

The music catalog ships a [`TimestampProfile`](../examples/music-catalog/src/Profile/TimestampProfile.php)
that stamps the moment a document was generated into the top-level `meta`. It
extends `AbstractProfile`, so `keywords()` only declares the one member it reserves
and the only hook it overrides is `finalizeDocument()`:

```php
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Profile\AbstractProfile;

final class TimestampProfile extends AbstractProfile
{
    public const URI = 'https://music.example/profiles/timestamp';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable();
    }

    public function uri(): string
    {
        return self::URI;
    }

    /** @return list<string> */
    public function keywords(): array
    {
        return ['generatedAt'];
    }

    public function finalizeDocument(array $document, JsonApiRequestInterface $request): array
    {
        $meta = $document['meta'] ?? [];
        if (!\is_array($meta)) {
            $meta = [];
        }

        $meta['generatedAt'] = ($this->clock)()->format(\DateTimeInterface::ATOM);
        $document['meta'] = $meta;

        return $document;
    }
}
```

A request asks for the profile through the `Accept` header's `profile` parameter or
the `profile` query parameter; if the server recognizes the URI, the hook runs and
the `generatedAt` member is stamped. The clock is injected, so a test can freeze it
(`TimestampProfile::frozenAt($instant)`) and assert a deterministic
`meta.generatedAt`. The hook is defensive: it tolerates a document with no `meta`
member, or a non-array one, rather than assuming the body shape.

Two details worth carrying into your own profiles:

- The hook works on the **assembled body array**, not on resource objects or
  response value objects — it is the last point before encoding, so it can touch
  any top-level member (`meta`, `links`, `jsonapi`) regardless of which response
  produced it.
- The hook runs once per applied profile per response. It is not a per-resource
  callback; if you need to enrich each resource, do that in the
  [serializer](serializers.md), not here.

## The bundled cursor-pagination profile

The library ships one production profile, `Pagination\CursorPaginationProfile`,
which advertises Ethan Resnick's published
[cursor-pagination profile](https://jsonapi.org/profiles/ethanresnick/cursor-pagination/):

```php
final class CursorPaginationProfile extends AbstractProfile
{
    public const string URI = 'https://jsonapi.org/profiles/ethanresnick/cursor-pagination/';

    public function uri(): string
    {
        return self::URI;
    }

    public function keywords(): array
    {
        return ['page[size]', 'page[after]', 'page[before]'];
    }
}
```

It reserves the `page[size]`, `page[after]`, and `page[before]` query parameters but
overrides no hook — it carries no `finalizeDocument()` body change, only the URI
advertisement. A `CursorBasedPage` activates it, so cursor-paginated responses
advertise the profile automatically once the server has registered it. See
[Pagination](pagination.md) for the cursor paginator and how a page declares its
profile.

## The bundled relationship-queries profile

The library also ships `Schema\Profile\RelationshipQueriesProfile`, whose canonical
URI is
[`https://haddowg.github.io/json-api/profiles/relationship-queries/`](profiles/relationship-queries.md)
— and that URI **resolves to the profile's specification**, written in the same
register as the cursor-pagination profile it sits beside (see the
[Relationship Queries profile spec](profiles/relationship-queries.md)). The profile
lets a client **filter and sort a relationship's linkage from the primary request**
— whether the relationship is rendered via `?include`, as links-only linkage, or at
its endpoint. It reserves two query-parameter families, both spec-compliant because
their base names each carry a non a-z character (a capital `Q`):

```
relatedQuery[<relationship-path>][sort]=-field,field
relatedQuery[<relationship-path>][filter][<key>]=<value>
rQ[<relationship-path>][sort]=-field            # rQ is the shorthand alias
```

The path is the relationship's **include path** (a dotted path like
`albums.tracks` is legal in the single bracket), not its type. `relatedQuery` is
canonical and `rQ` is a shorthand alias with identical semantics; on a conflict
targeting the same `[path][op]` the canonical `relatedQuery` wins. `page` is
deliberately **not** part of the profile — an addressed relationship always
renders **page 1**, navigated via the relationship object's own pagination links.

Like every profile it is **opt-in by negotiation**: the families are parsed only
when the client negotiated the profile URI (in the `Accept` `profile` parameter),
and are otherwise ignored entirely — so a relationship literally named after a
reserved family never collides. A structurally malformed param under the profile
(a non-array family, a non-string `sort`, a non-array `filter`) is a `400`
`QueryParamMalformed`; semantic validation of the sort/filter keys against the
relationship's vocabulary (and that the path resolves to a to-many relation) is
the host's, raising the same `400` the related-collection endpoint does.

Core exposes the parsed query through the request and supplies a render seam; the
host (e.g. the Doctrine bundle) does the page-1 windowing:

- `JsonApiRequestInterface::getRelatedQuery($path)` returns a read-only
  `Request\RelatedQuery` (`sort` raw string + `filter` map) for a path, and
  `RelatedQuery::toPlainQueryString()` translates it to the **plain-form**
  (`sort=…&filter[…]=…`) the relationship's own endpoint uses.
- `Server::withRelationshipPagination(...)` injects a
  `Serializer\RelationshipPaginationInterface` that windows a to-many relation to
  page 1 (ordered/filtered) and returns a
  `Schema\Relationship\RelationshipPagination` (page + plain-form query string).
  Core attaches it so the relationship object emits `first`/`prev`/`next`
  (+ `last` only when the relation is [`countable()`](relations.md#countable-relations-and-withcount)) links — in
  the spec's plain-form against the relationship-linkage endpoint, never the
  profile's `relatedQuery[…]` form (which only addresses a relationship from a
  parent request). With no resolver injected (standalone core) no
  relationship-object pagination links are emitted.

## Registering profiles

A profile becomes active once registered on a [`Server`](server.md). Every
`with…()` returns a new immutable instance, so registration is part of the same
fluent assembly that wires base URI, PSR-17 factories, and the default paginator
([`bootstrap.php`](../examples/music-catalog/src/bootstrap.php)):

```php
use haddowg\JsonApi\Examples\MusicCatalog\Profile\TimestampProfile;
use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApi\Server\Server;

$base = Server::make()
    ->withBaseUri('https://music.example')
    ->withPsr17($psr17, $psr17)
    ->withDefaultPaginator(PagePaginator::make()->withDefaultPerPage(10))
    ->withProfile(new TimestampProfile())
    ->withProfile(new CursorPaginationProfile())
    // …register resources…
;
```

Internally the server holds a `Schema\Profile\ProfileRegistry` — a per-instance map
keyed by URI, reachable via `Server::profiles()`. The registry is a plain eager map
(the spec requires no quality-factor negotiation across profiles, so lookup is an
O(1) URI match):

| Method | Returns | Purpose |
|---|---|---|
| `register(ProfileInterface)` | `void` | Add a profile (or pass profiles to the constructor). |
| `has(string $uri)` | `bool` | Whether a profile is registered for the URI. |
| `get(string $uri)` | `?ProfileInterface` | The profile for the URI, or `null`. |
| `all()` | `list<ProfileInterface>` | Every registered profile. |

Registering two profiles under the same URI is a **wiring error**: `register()`
throws `ProfileAlreadyRegistered`. That is a `\LogicException`, **not** a
[`JsonApiExceptionInterface`](errors-and-exceptions.md) — it should surface as a bug
to fix during assembly, never as an error document in a response.

## How applied profiles are surfaced

Profile *application* lives in the [response layer](responses.md), not on the
profile itself. When a response is rendered it resolves its applied profiles by
intersecting the URIs the request requested/required with the profiles the server
has registered — unrecognized URIs are dropped — and then:

- runs each applied profile's `finalizeDocument()` over the body;
- records the applied URIs in top-level `links.profile`;
- echoes them in the response `Content-Type` `profile` parameter;
- sets `Vary: Accept`.

A response subtype may add its own profile to that set — a cursor-paginated
`DataResponse::fromPage()` prepends the cursor page's profile — but still only when
the server has registered it, so a response never advertises a profile the server
does not recognize. The canonical detail of negotiation and emission lives in
[content negotiation](content-negotiation.md) and [responses](responses.md).

## Contributing to validation

A profile can also extend the optional [JSON Schema validation](schema-validation.md)
of documents while it is in scope. Implement
`Validation\SchemaContributingProfileInterface` (which extends `ProfileInterface`)
and return a decoded draft-2020-12 schema fragment from `schemaFragment()` (or
`null` to contribute nothing):

```php
interface SchemaContributingProfileInterface extends ProfileInterface
{
    public function schemaFragment(): ?object;
}
```

When such a profile is in scope for a request, the `DocumentValidator` composes the
fragment with the base schema via `allOf`. Because the composite owns the top-level
`unevaluatedProperties`, a fragment can both **add** constraints and **permit** the
profile's reserved top-level members that the base schema would otherwise reject.
See [Schema validation](schema-validation.md#profiles-that-contribute-a-fragment).

## Next / see also

- [Content negotiation](content-negotiation.md) — how `profile` (and `ext`) are negotiated, and why profiles are advisory while extensions are strict.
- [Pagination](pagination.md) — the bundled cursor-pagination profile and how a page activates it.
- [Responses](responses.md) — where applied profiles are emitted onto the document.
- [Schema validation](schema-validation.md) — profile schema fragments.
- [Server](server.md) — `withProfile()` and the rest of the assembly surface.
