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
| Resource identifier objects (`type`, `id`, `meta`) | ✅ test | `Schema\ResourceIdentifier` (construct-only `final readonly`); `fromArray()` validates required `type`/`id` and throws the typed `ResourceIdentifier*` exceptions directly (no `ExceptionFactory`); `meta` omitted from `transform()` when empty. `ResourceIdentifierTest`. |
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
| `include` query parameter; compound-document `included` | 🟡 code | Request-side `include` parsing implemented + tested (`JsonApiRequest::getIncludedRelationships()`/`isIncludedRelationship()`, `JsonApiRequestTest`). Compound-document `included` emission lands with the serialization engine. |

## Sparse fieldsets (`spec:sparse-fieldsets`)

| Requirement | Status | Notes |
|---|---|---|
| `fields[TYPE]` query parameter | 🟡 code | Request-side `fields[TYPE]` parsing implemented + tested (`JsonApiRequest::getIncludedFields()`/`isIncludedField()`, `JsonApiRequestTest`). Applying the fieldset to serialized output lands with the serialization engine. |

## Sorting (`spec:sorting`)

| Requirement | Status | Notes |
|---|---|---|
| `sort` query parameter parsing | ✅ test | `JsonApiRequest::getSorting()` parses the `sort` param (comma-separated, `-` prefix preserved) and throws `QueryParamMalformed` on a non-string value. `JsonApiRequestTest`. |

## Pagination (`spec:pagination`)

| Requirement | Status | Notes |
|---|---|---|
| `page[…]` query parameter parsing | ✅ test | Raw `page[…]` access (`JsonApiRequest::getPagination()`) plus the typed parsers `Request\Pagination\{Page,Offset,Cursor,FixedPage,FixedCursor}BasedPagination` + `PaginationFactory` (absent/non-numeric params fall back to defaults, per yin). `JsonApiRequestTest`, `tests/Request/Pagination/*`. Unify into a `Page` VO in Phase 2. |
| Pagination links (`first`/`prev`/`next`/`last`) | ⬜ todo | Link-provider port (Phase 1); refactored to `Page` in Phase 2. |

## Filtering (`spec:filtering`)

| Requirement | Status | Notes |
|---|---|---|
| `filter` query parameter (format-agnostic) | ✅ test | Request-side parsing implemented + tested (`JsonApiRequest::getFiltering()`/`getFilteringParam()`, `JsonApiRequestTest`). Execution remains adapter-provided by design. |

## CRUD (`spec:crud`)

| Requirement | Status | Notes |
|---|---|---|
| Create / update / delete resources & relationships | ⬜ todo | Lands with hydrators + operations. |

## Content negotiation (`spec:content-negotiation`)

| Requirement | Status | Notes |
|---|---|---|
| `Content-Type` / `Accept` handling; reject unknown media-type params | ✅ test | `JsonApiRequest::validateContentTypeHeader()`/`validateAcceptHeader()` (→ `MediaTypeUnsupported`/`MediaTypeUnacceptable`) plus the `Negotiation\RequestValidator`/`ResponseValidator` orchestrators. `JsonApiRequestTest`, `RequestValidatorTest`, `ResponseValidatorTest` (`#[Group('spec:content-negotiation')]`). **Note:** media-type params are **profile-only** (yin-faithful); `ext` parameter negotiation is not yet handled. JSON-schema body validation is deferred (later phase). |
