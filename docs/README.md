# Documentation

`haddowg/json-api` is a modern, server-side [JSON:API 1.1](https://jsonapi.org/format/1.1/)
library for PHP 8.3+. This is the documentation index; if you are new, start with
[Getting started](getting-started.md) for an end-to-end walkthrough, then dip into
the reference pages below as you need them.

## Getting started

- [Getting started](getting-started.md) — build a complete fetch + create endpoint from an empty project.

## Core concepts

- [Concepts](concepts.md) — the JSON:API document model and the vocabulary this package uses.
- [Architecture](architecture.md) — how the layers (request, Resource, engine, response) fit together.
- [Spec compliance](spec-compliance.md) — the canonical JSON:API 1.1 MUST/SHOULD coverage table.

## Defining resources

- [Resources](resources.md) — the recommended way to declare a resource type via a `Resource\AbstractResource` subclass (one list drives both directions).
- [Fields](fields.md) — every attribute and relationship field type and its fluent options.
- [Validation](validation.md) — field constraints, create/update contexts, and the per-resource JSON Schema compiler.
- [Filters](filters.md) — declaring the filters a type accepts and the handler that applies them.
- [Sorts](sorts.md) — field-derived and computed sort declarations.
- [Pagination](pagination.md) — the `Paginator` strategies and `Page` value objects.
- [Profiles](profiles.md) — implementing and registering JSON:API 1.1 profiles.
- [Serializers](serializers.md) — full control of serialization when a Resource class's fields aren't enough.
- [Hydrators](hydrators.md) — full control of hydration when a Resource class's fields aren't enough.

## Requests and responses

- [Server](server.md) — the per-version configuration root, operations, routing/targets, and dispatch.
- [Responses](responses.md) — the five response value objects and their `with…` chaining.
- [Content negotiation](content-negotiation.md) — media-type rules, `profile`/`ext` parameters, and validation.
- [Errors](errors.md) — the JSON:API error object and how error documents are built.
- [Exceptions](exceptions.md) — the typed exception hierarchy and the HTTP statuses they carry.

## Cross-cutting

- [Middleware](middleware.md) — the PSR-15 suite for the JSON:API request lifecycle.
- [Middleware order](middleware-order.md) — the recommended ordering and its rationale.
- [Adapters](adapters.md) — the metadata-in-core, handlers-in-adapters split for filters, sorts, and constraints.
- [Testing](testing.md) — assertion wrappers, request/operation builders, and the spec-compliance check.
