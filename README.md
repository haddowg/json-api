# haddowg/json-api

A modern, server-side [JSON:API 1.1](https://jsonapi.org/format/1.1/) library for PHP 8.3+.

[![CI](https://github.com/haddowg/json-api/actions/workflows/ci.yml/badge.svg)](https://github.com/haddowg/json-api/actions/workflows/ci.yml)

> **Part of the [jsonapi.rest](https://jsonapi.rest) suite** — a complete, spec-compliant
> JSON:API 1.1 stack for PHP: this **framework-agnostic core**, a
> [Symfony bundle](https://github.com/haddowg/json-api-symfony), a
> [Laravel package](https://github.com/haddowg/json-api-laravel), and a typed TypeScript
> client, bound together by one conformance-tested OpenAPI 3.1 contract.

`haddowg/json-api` is a typed, framework-agnostic toolkit for building
[JSON:API 1.1](https://jsonapi.org/format/1.1/) compliant APIs in modern PHP — serialising
resources, parsing and validating requests, negotiating content, and shaping responses
exactly as the specification requires, without tying you to any particular framework or data
layer.

The design leans on contemporary PHP: readonly value objects, enums, a typed exception
hierarchy, PSR-7 v2 and PSR-15 throughout, and a fluent schema layer for declaring a resource
type's attributes, relationships, filters, sorts, and validation in one place. First-class
support for JSON:API profiles is built in rather than bolted on.

## Requirements

- PHP 8.3, 8.4, or 8.5

## Installation

```bash
composer require haddowg/json-api
```

## Documentation

The full documentation is published at **[haddowg.github.io/json-api](https://haddowg.github.io/json-api/)**.
Start with [Getting started](https://haddowg.github.io/json-api/getting-started/) for an
end-to-end walkthrough, or browse the [documentation index](https://haddowg.github.io/json-api/)
for the reference pages.

Using a framework? The [Symfony bundle](https://github.com/haddowg/json-api-symfony) and
[Laravel package](https://github.com/haddowg/json-api-laravel) wrap this core with idiomatic
routing, validation, and a reference data layer.

## Try it

The [worked music-catalog example](examples/music-catalog/) serves itself — run
`docker compose up` in [`examples/music-catalog/`](examples/music-catalog/README.md) and browse
the live API at `http://localhost:8080/albums`. It is also the single source of truth for every
documentation snippet.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md). This project uses
[Conventional Commits](https://www.conventionalcommits.org/) and automated releases via
[release-please](https://github.com/googleapis/release-please).

## Credits

This package began as a derivative work based on [woohoolabs/yin](https://github.com/woohoolabs/yin),
and substantial portions of the codebase derive from it — sincere thanks to
**Woohoo Labs and the yin contributors** for the original library. The fluent schema layer
draws inspiration from [Laravel JSON:API](https://laraveljsonapi.io/), whose schema-first
developer experience shaped this project's recommended API.

## Licence

Released under the [MIT Licence](LICENSE), with dual copyright held by Gregory Haddow and
Woohoo Labs and contributors (the original woohoolabs/yin authors).
