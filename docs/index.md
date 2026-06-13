# haddowg/json-api — a server-side JSON:API 1.1 library for PHP

`haddowg/json-api` is a modern, server-side [JSON:API 1.1](https://jsonapi.org/format/1.1/)
library for PHP 8.3, 8.4 and 8.5. It is framework- and storage-agnostic: you bring
a domain model and a PSR-7/PSR-17 implementation, declare your resource types, and
the library handles negotiation, body parsing, sparse fieldsets, includes, error
rendering, and encoding. You stay in plain PHP value objects throughout — there is
no router and no ORM baked in.

> **Pre-1.0 — expect breaking changes.** This library is still `0.x`. Breaking
> changes can land between `0.x` minor releases and are recorded in the
> [changelog](https://github.com/haddowg/json-api/releases). Pin a version you have tested against, and read the
> changelog before upgrading. The instability warning and the install caveat below
> are stated once, here; every other page links back to this one rather than
> repeating them.

## What it does

The library exists to do four things well:

- **Verifiable JSON:API 1.1 compliance.** The spec's MUST/SHOULD rules are tracked
  against a [compliance ledger](spec-compliance.md), and the example app's test
  suite asserts every response is spec-compliant.
- **First-class server-side profiles.** [Profiles](profiles.md) are a built-in
  extension point, not an afterthought — you register them on the server and they
  participate in content negotiation.
- **A shipped PSR-15 middleware suite** for the standard request lifecycle —
  content negotiation, body parsing, error handling — so the common path is wired
  for you. See [middleware](middleware.md).
- **A stable, production-suitable foundation.** Immutable value objects, a typed
  exception hierarchy, and a single field declaration that drives both
  serialization and hydration.

## Scope boundaries

This is a **server-side** library. The following are deliberately out of scope:

- **Client-side consumption** — building or parsing requests as an API *consumer*.
- **Framework integration.** The library is framework-agnostic; idiomatic Symfony
  integration (DI, routing, the request lifecycle) lives in a separate bundle,
  `haddowg/json-api-symfony`.
- **Migration tooling** — there are no codegen or schema-migration helpers.

## Install

```bash
composer require haddowg/json-api
```

> **Not yet on Packagist.** Until the first stable release ships to Packagist you
> install from the Git repository — add it as a VCS repository in your
> `composer.json` and require `dev-main`. This caveat is stated once, here.

The library depends on a [PSR-7](https://www.php-fig.org/psr/psr-7/) HTTP message
implementation and a [PSR-17](https://www.php-fig.org/psr/psr-17/) factory; it does
not ship one. The examples and tests use [`nyholm/psr7`](https://github.com/Nyholm/psr7):

```bash
composer require nyholm/psr7
```

## A taste

You declare a resource type by subclassing `AbstractResource`, giving it a `$type`,
and listing its fields. One field declaration drives **both** directions — read and
write:

```php
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class AlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(200)->sortable(),
            // …
        ];
    }
}
```

You then register the resource on an immutable `Server` — the per-version
configuration root — and wire your PSR-17 factories:

```php
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;

$psr17 = new Psr17Factory();

$server = Server::make()
    ->withBaseUri('https://music.example')
    ->withPsr17($psr17, $psr17)
    ->register(AlbumResource::class);
```

This is a sketch, not a copy-paste runnable program — a real service also wires a
middleware chain, a router, and an operation handler. The
[getting-started walkthrough](getting-started.md) builds the whole thing end to end
against the [music-catalog example app](../examples/music-catalog/README.md), with
every snippet lifted from a CI-run test.

## Design philosophy

A few principles run through the whole library:

- **Immutable value objects.** The `Server`, responses, and parsed request data are
  readonly; every `with…()` method returns a new instance.
- **Enums and typed exceptions.** Fixed vocabularies (relationship modes,
  comparisons, media-type parameters) are enums; failures are a typed
  [exception hierarchy](errors-and-exceptions.md) carrying their own HTTP status.
- **One declaration, both directions.** A field listed in `fields()` describes how a
  member [serializes](serializers.md) *and* how it [hydrates](hydrators.md) — you do
  not maintain two parallel maps.
- **First-class profiles.** [Profiles](profiles.md) are a registered server concern,
  negotiated like any other media-type parameter.

## Credits and licence

This package derives substantial portions from
[`woohoolabs/yin`](https://github.com/woohoolabs/yin); its original authors'
copyright is preserved alongside this derivative work. The fluent field and
constraint layer is inspired by Laravel JSON:API. It is released under the **MIT**
licence with a dual copyright (Gregory Haddow; Woohoo Labs and contributors) — see
[`LICENSE`](../LICENSE).

## Where to go next

Start with the **[getting-started walkthrough](getting-started.md)**, then reach for
the reference pages as you need them:

- **Getting started** — [getting-started](getting-started.md) (the end-to-end
  walkthrough), [concepts](concepts.md) (the document model and vocabulary),
  [architecture](architecture.md) (how a request flows through the library).
- **Defining resources** — [resources](resources.md), [fields](fields.md),
  [field types](field-types.md), [ids](ids.md), [relations](relations.md),
  [constraints](constraints.md).
- **Querying** — [filters](filters.md), [sorts](sorts.md),
  [pagination](pagination.md),
  [sparse fieldsets and includes](sparse-fieldsets-and-includes.md).
- **Serialization & hydration control** — [serializers](serializers.md),
  [hydrators](hydrators.md),
  [capability composition](capability-composition.md).
- **Request/response lifecycle** — [server](server.md), [operations](operations.md),
  [related endpoints](related-endpoints.md),
  [relationship mutation](relationship-mutation.md), [responses](responses.md),
  [content negotiation](content-negotiation.md),
  [errors and exceptions](errors-and-exceptions.md), [middleware](middleware.md).
- **Cross-cutting** — [adapters](adapters.md),
  [schema validation](schema-validation.md), [profiles](profiles.md),
  [links and meta](links-and-meta.md), [security](security.md),
  [testing](testing.md), [spec compliance](spec-compliance.md).
