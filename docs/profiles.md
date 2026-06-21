# Profiles

A [JSON:API 1.1 profile](https://jsonapi.org/format/1.1/#profiles) is a named,
**portable extension to JSON:API's semantics** — additional document members, query
parameters, link relations, and processing rules — that a client and server agree on
by negotiating a single thing: the profile's **URI**. A profile is
**URI-identified** (its identity *is* that URI) and **independently implementable**:
the same URI-identified contract can be implemented by any server and recognised by
any client, with no shared code between them. The spec reserves a profile's member
names *for implementors* only in the sense that the profile's own specification
governs that namespace — it is not a statement about who profiles are "for".

This page shows you how the concept works as portable behaviour, then how *this
library* models a profile, registers it on a [`Server`](server.md), and has a
response that applies it advertise the profile back to the client.

Profiles are **advisory**. A server applies the profiles it recognizes and ignores
any it does not, so a profile a client asks for but the server has not registered is
silently dropped rather than rejected. That is the defining contrast with
[extensions](content-negotiation.md#profiles-flow-through-extensions-can-fail), which require strict
client/server agreement (a request asking for an extension the server does not
support is rejected with a `400`, never silently ignored).

## How a profile is negotiated

Profile negotiation is a behaviour defined by JSON:API itself, independent of any
implementation. A client asks for a profile by its **URI**, and a server that
recognises that URI applies the profile and advertises that it did:

- **The client asks.** It names the profile URI in the `profile` parameter of the
  request `Content-Type` (asserting *the request body* uses the profile) and/or of
  the `Accept` header (requesting the profile *on the response*), or via the
  `profile` **query parameter**.
- **The server applies and advertises.** A server that recognises the URI applies
  the profile, then advertises it three ways: it lists the URI in the top-level
  `jsonapi.profile` array, echoes it in the response `Content-Type` `profile`
  parameter, and sends `Vary: Accept`.
- **An unrecognised profile is ignored — never an error.** A profile the server does
  not recognise is silently dropped from the negotiated set; it is never rejected.

That whole exchange is concept, not code: any two conforming peers negotiate a
profile this way regardless of how either is built. The full mechanism — media-type
parameters, ordering, and how this contrasts with strict extensions — lives in
[content negotiation](content-negotiation.md#profiles-flow-through-extensions-can-fail).

## What a profile is

A profile is a **named bundle of extra JSON:API semantics**, identified by a URI and
defined by a written specification at (or pointed to by) that URI. Depending on what
the profile specifies, it may introduce additional **document members** (e.g. a new
`meta` member), additional **query parameters**, additional **link relations**, and
**processing rules** for how the server applies them. None of this requires shared
code: a client and server interoperate purely because both honour the same
published, URI-identified contract.

Everything below this point is about *how this library expresses that concept* — the
PHP types, hooks, and registration that turn a published profile into a running one.
Those are implementation mechanisms; the profile itself is the URI-identified
contract above.

## How this library models a profile

In this library a profile implements `Schema\Profile\ProfileInterface`. Of its three
methods, **only `uri()` corresponds to anything the JSON:API spec dictates** — it is
the profile's identity, the URI that is negotiated and advertised. `keywords()` and
`finalizeDocument()` are *this library's* mechanism for declaring reserved names and
for contributing document members; they are not concepts the JSON:API spec defines.

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

- **`uri()`** is the profile's canonical URI — the one method the spec dictates. It
  is the value matched against the negotiated `profile` media-type parameter,
  advertised in top-level `jsonapi.profile`, and echoed in the response
  `Content-Type` `profile` parameter.
- **`keywords()`** is **inert: it records, it does not reserve.** It lists the
  member, link-relation, and query-parameter names the profile's *specification*
  reserves, purely for documentation and introspection (and as a hook for future
  schema validation). It does **not** itself reserve those names — the base spec's
  namespacing rule does, via a non-`a-z` character in the base name — and it does
  **not** gate negotiation. A request that asks for a profile is honoured by URI
  alone; the keyword list is never inspected to decide whether the profile applies.
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

Its `keywords()` declares the `page[size]`, `page[after]`, and `page[before]` query
parameters, and it overrides no hook — it carries no `finalizeDocument()` body
change, only the URI advertisement. A `CursorBasedPage` activates it, so
cursor-paginated responses advertise the profile automatically once the server has
registered it. See [Pagination](pagination.md) for the cursor paginator and how a
page declares its profile.

**This `keywords()` list is not the profile's contract.** It declares only the query
parameters *this library* reads; it is **not** a restatement of the profile's full
vocabulary. The profile's normative definition — its complete query-parameter and
`meta.page` member vocabulary (e.g. `cursor`, `total`, `estimatedTotal`,
`rangeTruncated`, `maxSize`), its page semantics, and its error behaviour — is
**Ethan Resnick's published specification**
([jsonapi.org/profiles/ethanresnick/cursor-pagination](https://jsonapi.org/profiles/ethanresnick/cursor-pagination/)),
which is the authority. We advertise its URI; we do not re-specify it.

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
rQ[<relationship-path>][sort]=-field            # rQ is an OPTIONAL shorthand alias
```

The path is the relationship's **include path** (a dotted path like
`albums.tracks` is legal in the single bracket), not its type. `relatedQuery` is
canonical and `rQ` is an **optional** shorthand alias with identical semantics — the
alias is a **MAY** in the relationship-queries spec, so an implementation need not
support it; on a conflict targeting the same `[path][op]` the canonical
`relatedQuery` wins. `page` is deliberately **not** part of the profile — where the
server paginates the addressed relationship, it renders that relationship's **first
page**, navigated via the relationship object's own pagination links; the page size
and default ordering are the related collection's own, not the profile's.

Note that the `rQ` alias and the canonical-`relatedQuery`-wins precedence are
definitions in **this profile's own specification** (the
[relationship-queries spec](profiles/relationship-queries.md)), not general
features of "profiles". A different profile would define a different vocabulary.

Like every profile it is **opt-in by negotiation**: the families are parsed and
applied only when the client negotiated the profile URI (in the `Accept` `profile`
parameter). Un-negotiated, they carry none of the profile's semantics — a server
**MAY** ignore them or, under strict query-parameter validation, reject them as an
unrecognised custom family — so a relationship literally named after a reserved
family never takes on profile meaning. A structurally malformed param under the profile
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

## The bundled Countable profile

The library also ships `Schema\Profile\CountableProfile`, whose canonical
URI is
[`https://haddowg.github.io/json-api/profiles/countable/`](profiles/countable.md)
— and that URI **resolves to the profile's specification** (see the
[Countable profile spec](profiles/countable.md)). The profile lets a client ask for
**the size of a countable collection** alongside the primary resource — a named
relationship's set and/or the **primary collection itself** via the reserved
`_self_` token — through a single flat query-parameter family, `withCount`
(spec-compliant because its base carries an uppercase `C`):

```
withCount=_self_,comments,tags
```

`withCount` is the same flat, comma-separated shape as `?include`. Each named
to-many relationship the server has made [`countable()`](relations.md#countable-relations-and-withcount)
gets a `total` member on its relationship object's `meta`; the reserved `_self_`
token counts the primary collection (a [`countable()`](pagination.md#counting-and-totals)
resource) onto the top-level `meta.total` — and `meta.page.total` when the collection
is paginated, from a single count. Like every profile it is **opt-in by
negotiation**: the family is parsed and applied only when the client negotiated the
profile URI; un-negotiated it carries no profile meaning (a server **MAY** ignore it
or reject it under strict query-parameter validation). Naming a non-countable relation, a to-one, an unknown
relationship, or `_self_` against a non-countable resource is a `400` with
`source.parameter` `withCount`. The count reflects the *filtered* set, so it agrees
with the total the collection's own endpoint reports.

The reserved `_self_` token is a definition in **this profile's own specification**
(the [Countable profile spec](profiles/countable.md)), not a general feature of
"profiles" — like the relationship-queries vocabulary above, it is part of *this*
profile's contract.

## Registering profiles

A profile becomes active once registered on a [`Server`](server.md). "Recognising" a
profile is, in JSON:API terms, simply the server choosing to apply it; in this
library that choice is expressed by registering the profile on the `Server`.
Registration is *our* mechanism, not a spec concept. Every `with…()` returns a new
immutable instance, so registration is part of the same fluent assembly that wires
base URI, PSR-17 factories, and the default paginator
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
- records the applied URIs in top-level `jsonapi.profile`;
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
