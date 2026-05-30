# JSON:API 1.1 spec compliance

> **Scope.** This document tracks **[JSON:API 1.1](https://jsonapi.org/format/1.1/)
> specification compliance only** — the normative MUST/SHOULD requirements of the
> format and how this package satisfies them. It is *not* an OpenAPI document and
> must not be conflated with OpenAPI spec generation (a separate, post-1.0
> candidate). It is a living checklist, filled in progressively as each subsystem
> is ported; it is the truth-of-record for the remaining spec-compliance gap.

## Status legend

| Status | Meaning |
|---|---|
| ✅ test | Requirement implemented **and** covered by a test (tagged `#[Group('spec:<section>')]`). |
| 🟡 code | Implemented in code but not yet covered by a dedicated test. |
| ⬜ todo | Not yet implemented in this package. |
| 🚫 n/a | Intentionally unsupported / out of scope (rationale given). |

Spec-section anchors map to the `spec:<section>` PHPUnit groups (see
[`tests/README.md`](../tests/README.md)).

## Document structure (`spec:document-structure`)

| Requirement | Status | Notes |
|---|---|---|
| Top-level `jsonapi` object with `version` member | ✅ test | `Schema\JsonApiObject`; defaults to version `1.1` via `JsonApiObject::VERSION`. `JsonApiObjectTest`. |
| `jsonapi.meta` is a free-form meta object, omitted when empty | ✅ test | `JsonApiObject::transform()` omits empty `meta`. `JsonApiObjectTest`. |
| Links: bare-string or link-object (`{href, …}`) forms | ✅ test | `Schema\Link\Link` / `LinkObject`. `LinkTest`, `LinkObjectTest`. |
| Link object members `href`, `rel`, `title`, `type`, `hreflang`, `meta` | ✅ test | `LinkObject` models all; empty members omitted. `LinkObjectTest`. |
| Link object `describedby` member | ⬜ todo | Deferred until the Links container types are ported (nests a `Link`). TODO marker in `LinkObject`. |
| Templated links (RFC 6570) | ✅ test | No dedicated `templated` member exists in JSON:API 1.1; a templated link is a plain string `href`, representable as-is. (Decision log, Link audit.) |
| Profile link object with keyword `aliases` | ✅ test | `Schema\Link\ProfileLinkObject`. `ProfileLinkObjectTest`. Full profile association is Phase 2. |
| Top-level `meta` member | ⬜ todo | Lands with the document classes / `MetaResponse`. |
| Links containers (`DocumentLinks`, `ResourceLinks`, `RelationshipLinks`, `ErrorLinks`) | ✅ test | Construct-only `final readonly` extending `AbstractLinks`; custom relation keys allowed; pagination links accepted as plain `?Link` (Page-based emission is Phase 2). `DocumentLinksTest`, `ResourceLinksTest`, `RelationshipLinksTest`. |
| Top-level `links` member wiring into a document | ⬜ todo | Container types exist; binding them onto a document lands with the document classes. |
| `data` / `errors` / `meta` mutual exclusivity & required members | ⬜ todo | Lands with the document hierarchy. |
| Resource objects (`type`, `id`, `attributes`, `relationships`, `links`, `meta`) | ⬜ todo | Lands with `Schema\Resource\*`. |
| Resource identifier objects (`type`, `id`, `meta`) | ⬜ todo | Lands with `ResourceIdentifier`. |
| Compound documents / `included` | ⬜ todo | Lands with the serialization engine port. |

## Errors (`spec:errors`)

| Requirement | Status | Notes |
|---|---|---|
| Error `source` object (`pointer`, `parameter`, `header`) | 🟡 code | `Schema\Error\ErrorSource` covers `pointer` + `parameter` (✅ test); `header` member not yet modelled. `ErrorSourceTest`. |
| Error object members (`id`, `links`, `status`, `code`, `title`, `detail`, `source`, `meta`) | ✅ test | `Schema\Error\Error` (construct-only; each member omitted from `transform()` when empty). `ErrorTest`. |
| Error `links` (`about`, `type`) | ✅ test | `Schema\Link\ErrorLinks` (construct-only; `type` links de-duped by href). `ErrorLinksTest`. |
| Error document (top-level `errors` array) | ⬜ todo | Lands with `ErrorDocument` / `ErrorResponse`. |
| Typed exception → HTTP status mapping | ✅ test | 33 concrete `Exception\*` classes implementing `JsonApiException` (`getErrors(): list<Error>`, `getStatusCode()`); status/code/title/detail preserved from yin. `JsonApiExceptionTest`, `ExceptionErrorDetailTest`. |

## Fetching data (`spec:fetching-resources`, `spec:fetching-relationships`, `spec:fetching-data`)

| Requirement | Status | Notes |
|---|---|---|
| Fetch individual / collection resources | ⬜ todo | Lands with resources + operations. |
| Fetch relationships / related resources | ⬜ todo | Lands with relationship types + operations. |

## Inclusion of related resources (`spec:inclusion-of-related-resources`)

| Requirement | Status | Notes |
|---|---|---|
| `include` query parameter; compound-document `included` | ⬜ todo | Lands with the serialization engine port (backbone). |

## Sparse fieldsets (`spec:sparse-fieldsets`)

| Requirement | Status | Notes |
|---|---|---|
| `fields[TYPE]` query parameter | ⬜ todo | Lands with the serialization engine port. |

## Sorting (`spec:sorting`)

| Requirement | Status | Notes |
|---|---|---|
| `sort` query parameter parsing | ⬜ todo | Lands with request/query-parameter parsing. |

## Pagination (`spec:pagination`)

| Requirement | Status | Notes |
|---|---|---|
| `page[…]` query parameter parsing | ⬜ todo | Request-side pagination parsers (Phase 1); `Page` value objects (Phase 2). |
| Pagination links (`first`/`prev`/`next`/`last`) | ⬜ todo | Link-provider port (Phase 1); refactored to `Page` in Phase 2. |

## Filtering (`spec:filtering`)

| Requirement | Status | Notes |
|---|---|---|
| `filter` query parameter (format-agnostic) | ⬜ todo | Request-side parsing only in core; execution is adapter-provided. |

## CRUD (`spec:crud`)

| Requirement | Status | Notes |
|---|---|---|
| Create / update / delete resources & relationships | ⬜ todo | Lands with hydrators + operations. |

## Content negotiation (`spec:content-negotiation`)

| Requirement | Status | Notes |
|---|---|---|
| `Content-Type` / `Accept` handling; reject unknown media-type params (only `ext`/`profile` significant) | ⬜ todo | Lands with the negotiation port. |
