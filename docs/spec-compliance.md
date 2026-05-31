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
| Link object `describedby` member | ✅ test | `LinkObject` carries an optional `?Link $describedby` emitted by `transform()`. `LinkObjectDescribedbyTest`. |
| Templated links (RFC 6570) | ✅ test | No dedicated `templated` member exists in JSON:API 1.1; a templated link is a plain string `href`, representable as-is. (Decision log, Link audit.) |
| Profile link object with keyword `aliases` | ✅ test | `Schema\Link\ProfileLinkObject`. `ProfileLinkObjectTest`. Full profile association is Phase 2. |
| Top-level `meta` member | ✅ test | `Response\MetaResponse` (meta-only document) and the `withMeta()` wither on every response render into top-level `meta`. `MetaResponseTest`, `DataResponseTest`. |
| Links containers (`DocumentLinks`, `ResourceLinks`, `RelationshipLinks`, `ErrorLinks`) | ✅ test | Construct-only `final readonly` extending `AbstractLinks`; custom relation keys allowed; pagination links accepted as plain `?Link` (Page-based emission is Phase 2). `DocumentLinksTest`, `ResourceLinksTest`, `RelationshipLinksTest`. |
| Top-level `links` member wiring into a document | ✅ test | `withLinks(DocumentLinks)` on every response renders into top-level `links`. `DataResponseTest`. |
| `data` / `errors` / `meta` mutual exclusivity & required members | ✅ test | Each response value object emits exactly one primary member by construction — `DataResponse`→`data`, `MetaResponse`→`meta`, `ErrorResponse`→`errors` (the type system makes coexistence unconstructable); the request-side guard is also enforced + tested (`JsonApiRequest::validateTopLevelMembers`). `DataResponseTest`, `MetaResponseTest`, `ErrorResponseTest`. |
| Resource objects (`type`, `id`, `attributes`, `relationships`, `links`, `meta`) | ✅ test | `Schema\Resource\AbstractResource`/`ResourceInterface` (consumer extension point) + `Transformer\ResourceTransformer`. `AbstractResourceTest`, `ResourceTransformerTest`. |
| Resource identifier objects (`type`, `id`/`lid`, `meta`) | ✅ test | `Schema\ResourceIdentifier` (construct-only `final readonly`); `fromArray()` validates `type` + at-least-one-of(`id`,`lid`) and throws the typed `ResourceIdentifier*` exceptions directly (no `ExceptionFactory`); `transform()` emits whichever of `id`/`lid`/`meta` are present. `ResourceIdentifierTest`. |
| Compound documents / `included` | ✅ test | `Transformer\ResourceTransformer` + `DocumentTransformer` build the `included` array with resource dedup (primary takes precedence) via the `Schema\Data` accumulator. `ResourceTransformerTest`, `DocumentTransformerTest`. |

## Errors (`spec:errors`)

| Requirement | Status | Notes |
|---|---|---|
| Error `source` object (`pointer`, `parameter`, `header`) | ✅ test | `Schema\Error\ErrorSource` models `pointer`, `parameter` and `header` (with `fromPointer`/`fromParameter`/`fromHeader` named ctors), each omitted from `transform()` when empty. `ErrorSourceTest`, `ErrorSourceHeaderTest`. |
| Error object members (`id`, `links`, `status`, `code`, `title`, `detail`, `source`, `meta`) | ✅ test | `Schema\Error\Error` (construct-only; each member omitted from `transform()` when empty). `ErrorTest`. |
| Error `links` (`about`, `type`) | ✅ test | `Schema\Link\ErrorLinks` (construct-only; `type` links de-duped by href). `ErrorLinksTest`. |
| Error document (top-level `errors` array) | ✅ test | `Response\ErrorResponse` (`fromErrors()`/`fromException()`) renders the top-level `errors` array via `ErrorDocument`; HTTP status derived from the errors. `ErrorResponseTest`, `AbstractErrorDocumentTest`. |
| Typed exception → HTTP status mapping | ✅ test | 33 concrete `Exception\*` classes implementing `JsonApiException` (`getErrors(): list<Error>`, `getStatusCode()`); status/code/title/detail preserved from yin. `JsonApiExceptionTest`, `ExceptionErrorDetailTest`. |

## Fetching data (`spec:fetching-resources`, `spec:fetching-relationships`, `spec:fetching-data`)

| Requirement | Status | Notes |
|---|---|---|
| Fetch individual / collection resources | ✅ test | `FetchResourceOperation` + `Psr7ToOperationHandlerAdapter` (GET → operation → handler → `DataResponse` → PSR-7) end-to-end; `DataResponse::fromResource`/`fromCollection`. `Psr7ToOperationHandlerAdapterTest`, `DataResponseTest`. (URL→`Target` routing is Phase 3.) |
| Fetch relationships / related resources | ✅ test | `FetchRelatedOperation`/`FetchRelationshipOperation` (dispatched by target shape) + `Response\RelatedResponse`/`IdentifierResponse`. `Psr7ToOperationHandlerAdapterTest`, `RelatedResponseTest`, `IdentifierResponseTest`. Relationship-endpoint documents also carry top-level `jsonapi`/`meta`/`links` (merged over the relationship's own members). `RelationshipDocumentMetaTest`. |

## Inclusion of related resources (`spec:inclusion-of-related-resources`)

| Requirement | Status | Notes |
|---|---|---|
| `include` query parameter; compound-document `included` | ✅ test | Request-side `include` parsing (`JsonApiRequest`) **and** engine-side application: `Transformer\ResourceTransformer` honours `include`/default-included relationships and emits the deduped `included` array. `JsonApiRequestTest`, `ResourceTransformerTest`, `DocumentTransformerTest`. (Default-included detection unified across both transform paths — see decision log.) |

## Sparse fieldsets (`spec:sparse-fieldsets`)

| Requirement | Status | Notes |
|---|---|---|
| `fields[TYPE]` query parameter | ✅ test | Request-side parsing **and** engine-side application: `Transformer\ResourceTransformer` filters attributes/relationships by `isIncludedField()`. `JsonApiRequestTest`, `ResourceTransformerTest`. |

## Sorting (`spec:sorting`)

| Requirement | Status | Notes |
|---|---|---|
| `sort` query parameter parsing | ✅ test | `JsonApiRequest::getSorting()` parses the `sort` param (comma-separated, `-` prefix preserved) and throws `QueryParamMalformed` on a non-string value. `JsonApiRequestTest`. |

## Pagination (`spec:pagination`)

| Requirement | Status | Notes |
|---|---|---|
| `page[…]` query parameter parsing | ✅ test | Raw `page[…]` access (`JsonApiRequest::getPagination()`) plus the strategy paginators `Pagination\{Page,Offset,FixedPage}Paginator` (implement `Paginator`) and the standalone `Pagination\CursorPaginator`, which read `page[…]` and produce `Page` value objects (absent/non-numeric params fall back to defaults, per yin's rule, via `@internal QueryParam::int`). `tests/Pagination/PaginatorTest.php`. |
| Pagination links (`first`/`prev`/`next`/`last`) | ✅ test | The `Pagination\{PageBased,OffsetBased,FixedPage,CursorBased}Page` value objects emit `linkSet()` (built via `Transformer\Utils::getUri`, preserving unrelated query params) and `pageMeta()`; `DataResponse::fromPage()` renders them into top-level `links` + `meta.page`. **`CursorBasedPage` omits `last` by design** (no total count). `tests/Pagination/*PageTest.php`, `DataResponsePaginationTest`. Replaces yin's `PaginationLinkProviderInterface` + collection-side traits (deleted). |

## Filtering (`spec:filtering`)

| Requirement | Status | Notes |
|---|---|---|
| `filter` query parameter (format-agnostic) | ✅ test | Request-side parsing implemented + tested (`JsonApiRequest::getFiltering()`/`getFilteringParam()`, `JsonApiRequestTest`). Execution remains adapter-provided by design. |

## CRUD (`spec:crud`)

| Requirement | Status | Notes |
|---|---|---|
| Create / update / delete resources & relationships | ✅ test | Hydration (`Hydrator\AbstractHydrator` + traits, client-gen-id, relationship cardinality) **and** the operation/adapter wiring for POST/PATCH/DELETE on resources + relationships (`Create`/`Update`/`Delete`/`AddToRelationship`/`UpdateRelationship`/`RemoveFromRelationship`Operation, dispatched by `Psr7ToOperationHandlerAdapter`). `*HydratorTraitTest`, `Psr7ToOperationHandlerAdapterTest`, per-operation tests. (Consumer supplies the handler logic; URL→`Target` routing is Phase 3.) |
| Client-generated IDs on create (`id`) | ✅ test | `CreateHydratorTrait::hydrateIdForCreate()` + `validateClientGeneratedId()` (throws `ClientGeneratedIdNotSupported`/`ResourceIdInvalid`); non-string `id` rejected. `CreateHydratorTraitTest`. |
| Local identifiers (`lid`) on resource objects / resource identifiers (creation) | ✅ test | Added beyond yin. `ResourceIdentifier` carries `?id`/`?lid` (`fromArray()` requires `type` + at-least-one-of; `ResourceIdentifierTest`); a relationship referenced by `lid` hydrates through to the relationship hydrator (`CreateHydratorTraitTest`); the request exposes `getResourceLid()` (`JsonApiRequestTest`). **Scope:** accept/carry `lid` only — cross-document `lid`→resource *resolution* is deferred to the Atomic Operations extension (post-1.0). |

## Content negotiation (`spec:content-negotiation`)

| Requirement | Status | Notes |
|---|---|---|
| `Content-Type` / `Accept` handling; reject unknown media-type params | ✅ test | `JsonApiRequest::validateContentTypeHeader()`/`validateAcceptHeader()` (→ `MediaTypeUnsupported`/`MediaTypeUnacceptable`) plus the `Negotiation\RequestValidator`/`ResponseValidator` orchestrators. Only `ext` and `profile` are permitted media-type params (`Request\MediaType::isValid()`, quote-aware multi-instance split); any other param → 415/406. `JsonApiRequestTest`, `RequestValidatorTest`, `ResponseValidatorTest` (`#[Group('spec:content-negotiation')]`). JSON-schema body validation is deferred (later phase). |
| `ext` parameter negotiation (415/406 on unsupported extensions) | ✅ test | `RequestValidator(string ...$supportedExtensions)` rejects an `ext` not in its supported set: 415 on `Content-Type`, 406 on `Accept`. Empty supported set by default (no extensions shipped — the hook a post-1.0 Atomic Operations `ext` plugs into). `RequestValidatorTest`, `ExtensionTest`. |

## Extensions and profiles (`spec:extensions-and-profiles`)

| Requirement | Status | Notes |
|---|---|---|
| `profile` media-type parameter parsed on `Content-Type`/`Accept` (+ `profile` query param) | ✅ test | `JsonApiRequest::getAppliedProfiles()`/`getRequestedProfiles()`/`getRequiredProfiles()` (space-separated URI lists). `JsonApiRequestTest`, `ExtensionTest`. |
| `ext` media-type parameter parsed | ✅ test | `JsonApiRequest::getAppliedExtensions()`/`getRequestedExtensions()`. `ExtensionTest`. |
| Server MUST ignore unrecognized profiles (never reject) | ✅ test | Profiles are advisory: `RequestValidator` never rejects on a profile; the response only applies server-registered profiles and silently drops the rest. `RequestValidatorTest::negotiateIgnoresUnrecognizedProfiles`, `ProfileApplicationTest::ignoresUnregisteredRequestedProfile`. |
| Applied profile advertised in response `Content-Type` `profile` param | ✅ test | `Response\AbstractResponse::toPsrResponse()` echoes applied profile URIs; sets `Vary: Accept`. `ProfileApplicationTest`, `DataResponsePaginationTest`. |
| Top-level `links.profile` carries applied profile URIs | ✅ test | Populated by the response render path from the applied-profile set. `ProfileApplicationTest`. |
| Profile abstraction + registry | ✅ test | `Schema\Profile\{ProfileInterface,AbstractProfile,ProfileRegistry}`; `finalizeDocument()` document hook; duplicate-URI registration throws `ProfileAlreadyRegistered`. `ProfileRegistryTest`, `AbstractProfileTest`. |
| Cursor-pagination profile (first consumer) | ✅ test | `Pagination\CursorPaginationProfile` (`…/ethanresnick/cursor-pagination/`); `CursorBasedPage` activates it, so cursor-paginated responses advertise it on `Content-Type` + `links.profile`. `DataResponsePaginationTest::cursorResponseOmitsLastAndAdvertisesTheCursorProfile`. |
| Extensions cannot be used unless server-supported (415/406) | ✅ test | See the content-negotiation `ext` row. No extension ships in 1.0; the negotiation path is wired and tested against the empty supported set. |
