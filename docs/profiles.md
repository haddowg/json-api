# Profiles

A [JSON:API 1.1 profile](https://jsonapi.org/format/1.1/#profiles) is a named set
of document members and processing rules, reserved for implementors, that a server
*may* apply to a response. `haddowg/json-api` ships general-purpose profile
infrastructure: you implement a profile, register it on a [`Server`](server.md),
and a response that applies it advertises the profile to the client. Profiles are
**advisory** — a server applies the profiles it recognizes and ignores any it does
not, so a profile a client asks for but the server has not registered is silently
dropped rather than rejected. This is the defining difference from
[extensions](content-negotiation.md#extensions-ext), which demand strict
client/server agreement.

## The profile contract

A profile implements `Schema\Profile\ProfileInterface`:

```php
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

- `uri()` is the profile's canonical URI. It is the value matched against the
  negotiated `profile` media-type parameter, advertised in top-level
  `links.profile`, and echoed in the response `Content-Type` `profile` parameter.
- `keywords()` lists the member, link-relation, and query-parameter names the
  profile reserves. It is for documentation and introspection; it does **not**
  gate negotiation.
- `finalizeDocument()` is a finalisation hook run once for the profile, after the
  document body array has been assembled and before it is encoded. It receives the
  body and the active request and returns the (possibly augmented) body. Only
  profiles the server has applied are run.

`Schema\Profile\AbstractProfile` is the convenience base: it defaults `keywords()`
to `[]` and `finalizeDocument()` to the identity, so a subclass need only
implement `uri()` and override the hooks it actually uses. The contract remains
implementable by composition — the base is an ergonomic shortcut, not a
requirement.

## A custom profile

A profile that stamps a generated-at timestamp into the document `meta`:

```php
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Profile\AbstractProfile;

final class TimestampProfile extends AbstractProfile
{
    public const string URI = 'https://example.test/profiles/timestamp';

    public function uri(): string
    {
        return self::URI;
    }

    public function keywords(): array
    {
        return ['generated'];
    }

    public function finalizeDocument(array $document, JsonApiRequestInterface $request): array
    {
        $document['meta']['generated'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        return $document;
    }
}
```

The bundled `Pagination\CursorPaginationProfile` is a worked example: it extends
`AbstractProfile`, returns the published Ethan Resnick cursor-pagination URI from
`uri()`, and reserves `page[size]`, `page[after]`, and `page[before]` in
`keywords()`. A `CursorBasedPage` activates it so cursor-paginated responses
advertise the profile automatically — see [Pagination](pagination.md).

## Registering profiles

A profile becomes active when registered on a [`Server`](server.md), which returns
a new immutable instance:

```php
use haddowg\JsonApi\Server\Server;

$server = Server::make()
    ->withPsr17($psr17, $psr17)
    ->withProfile(new TimestampProfile());
```

Internally the server holds a `Schema\Profile\ProfileRegistry`, a per-instance map
keyed by URI, reachable via `Server::profiles()`. The registry's API is a simple
map:

| Method | Returns | Purpose |
|---|---|---|
| `register(ProfileInterface)` | `void` | Add a profile (or pass profiles to the constructor). |
| `has(string $uri)` | `bool` | Whether a profile is registered for the URI. |
| `get(string $uri)` | `?ProfileInterface` | The profile for the URI, or `null`. |
| `all()` | `list<ProfileInterface>` | Every registered profile. |

Registering two profiles under the same URI is a wiring error: `register()` throws
`ProfileAlreadyRegistered`. That is a `\LogicException`, **not** a
[`JsonApiExceptionInterface`](exceptions.md) — it should surface as a bug to fix, never as
an error document in a response.

## How applied profiles are surfaced

Profile *application* lives in the [response layer](responses.md), not on the
profile. When a response is rendered, the response resolves its applied profiles by
intersecting the URIs the request requested/required with the profiles the server
has registered (unrecognized ones dropped), then:

- runs each applied profile's `finalizeDocument()` over the body;
- records the applied URIs in top-level `links.profile`;
- echoes them in the response `Content-Type` `profile` parameter;
- sets `Vary: Accept`.

A response subtype may add its own profile to that set — for example a paginated
`DataResponse::fromPage()` prepends a cursor page's profile, but only when the
server has registered it, so a response never advertises a profile the server does
not recognize.

## Contributing to validation

A profile can also extend the optional [JSON Schema validation](validation.md) of
documents while it is in scope. Implement `Validation\SchemaContributingProfileInterface`
(which extends `ProfileInterface`) and return a decoded draft-2020-12 schema
fragment from `schemaFragment()`; the `DocumentValidator` composes that fragment
with the base schema for requests that have the profile in scope. See
[Validation](validation.md#profile-fragments).

## Related pages

- [Content negotiation](content-negotiation.md) — how `profile` (and `ext`) are negotiated, and why profiles are advisory.
- [Pagination](pagination.md) — the bundled cursor-pagination profile.
- [Validation](validation.md) — profile schema fragments.
- [Responses](responses.md) — where applied profiles are emitted.
