# haddowg/json-api

A modern, server-side [JSON:API 1.1](https://jsonapi.org/format/1.1/) library for PHP 8.3+.

[![CI](https://github.com/haddowg/json-api/actions/workflows/ci.yml/badge.svg)](https://github.com/haddowg/json-api/actions/workflows/ci.yml)

> [!WARNING]
> **Pre-1.0 — under active development.** The public API is not yet stable and
> **breaking changes may occur between `0.x` minor versions**. Each such change
> is recorded in the changelog. Wait for `1.0.0` if you need a stable surface.

## About

`haddowg/json-api` is a server-side library for building [JSON:API 1.1](https://jsonapi.org/format/1.1/)
compliant APIs in modern PHP. It gives you a typed, framework-agnostic toolkit
for serialising resources, parsing and validating requests, negotiating content,
and shaping responses exactly as the specification requires — without tying you
to any particular framework or data layer.

The design leans on contemporary PHP: readonly value objects, enums, a typed
exception hierarchy, PSR-7 v2 and PSR-15 throughout, and a fluent schema layer as
the recommended way to declare a resource type's attributes, relationships,
filters, sorts, and validation in one place. First-class support for JSON:API
profiles is built in rather than bolted on.

### Goals

- 100% verifiable JSON:API 1.1 specification compliance
- First-class, server-side support for JSON:API profiles
- A PSR-15 middleware suite for the standard JSON:API request lifecycle
- A stable, well-tested foundation suitable for production use

Client-side support, framework integrations, and migration tooling are out of
scope for the core package.

## Requirements

- PHP 8.3, 8.4, or 8.5

## Installation

> Not yet published to Packagist. Once the first `0.x` release is cut:

```bash
composer require haddowg/json-api
```

## Quick example

Declare a resource type once — the same field list drives serialisation and
hydration — register it on a `Server`, and hand the server a PSR-7 request:

```php
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;

final class ArticleResource extends AbstractResource
{
    public static string $type = 'articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(255)->sortable(),
            Str::make('body')->required(),
        ];
    }
}

$psr17 = new Psr17Factory();

$server = Server::make()
    ->withBaseUri('https://example.test')
    ->withPsr17($psr17, $psr17)
    ->register(ArticleResource::class)
    ->withHandler($articleHandler); // your OperationHandler

$response = $server->handle($request); // a spec-compliant PSR-7 response
```

See [Getting started](https://haddowg.github.io/json-api/getting-started/) for the handler, routing, and
middleware that complete this example.

## Documentation

The full documentation is published at **[haddowg.github.io/json-api](https://haddowg.github.io/json-api/)**.
Start with [Getting started](https://haddowg.github.io/json-api/getting-started/) for an end-to-end
walkthrough, or browse the [documentation index](https://haddowg.github.io/json-api/) for the reference
pages. The source Markdown lives under [`docs/`](docs/index.md) in the repository.

The [worked music-catalog example](examples/music-catalog/) is the single source
of truth for every documentation snippet — and it serves itself. Run
`docker compose up` in [`examples/music-catalog/`](examples/music-catalog/README.md#serving-it-live)
and browse the live API at `http://localhost:8080/albums`.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md). This project uses
[Conventional Commits](https://www.conventionalcommits.org/) and automated
releases via [release-please](https://github.com/googleapis/release-please).

## Credits

This package began as a derivative work based on [woohoolabs/yin](https://github.com/woohoolabs/yin),
and substantial portions of the codebase derive from it — sincere thanks to
**Woohoo Labs and the yin contributors** for the original library. The fluent
schema layer draws inspiration from [Laravel JSON:API](https://laraveljsonapi.io/),
whose schema-first developer experience shaped this project's recommended API.

## Licence

Released under the [MIT Licence](LICENSE), with dual copyright held by Gregory
Haddow and Woohoo Labs and contributors (the original woohoolabs/yin authors).
