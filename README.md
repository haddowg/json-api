# haddowg/json-api

A modern, server-side [JSON:API 1.1](https://jsonapi.org/format/1.1/) library for PHP 8.3+.

[![CI](https://github.com/haddowg/json-api/actions/workflows/ci.yml/badge.svg)](https://github.com/haddowg/json-api/actions/workflows/ci.yml)

> [!WARNING]
> **Pre-1.0 — under active development.** This package is being built in phases
> (see [`docs/PLAN.md`](docs/PLAN.md)). The public API is not yet stable and
> **breaking changes may occur between `0.x` minor versions**. Each such change
> is recorded in the changelog. Wait for `1.0.0` if you need a stable surface.

## About

`haddowg/json-api` is a fork and modernisation of the (now effectively
abandoned) [woohoolabs/yin](https://github.com/woohoolabs/yin). It targets
PHP 8.3+ and embraces modern language features (readonly classes, enums, typed
properties, constructor promotion, first-class callable syntax), a typed
exception hierarchy, PSR-7 v2 and PSR-15, first-class JSON:API profile support,
and a fluent schema layer as the recommended public surface.

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

_Coming in Phase 1 — a worked GET/POST example using the response value objects
and operation handlers. Until then, see [`docs/PLAN.md`](docs/PLAN.md) for the
intended public API shape._

## Documentation

Project planning and phase documents live under [`docs/`](docs/):

- [`docs/PLAN.md`](docs/PLAN.md) — the master plan, high-level decisions, and phase index

Consumer-facing documentation is produced in Phase 5.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md). This project uses
[Conventional Commits](https://www.conventionalcommits.org/) and automated
releases via [release-please](https://github.com/googleapis/release-please).

## Acknowledgements

This package is a fork of [woohoolabs/yin](https://github.com/woohoolabs/yin)
and substantial portions of the codebase derive from that work. Sincere thanks
to **Woohoo Labs and the yin contributors** for the original library, which made
this project possible.

## Licence

Released under the [MIT Licence](LICENSE), with dual copyright held by Gregory
Haddow (this fork) and Woohoo Labs and contributors (the original
woohoolabs/yin authors).
