# JSON:API 1.1 spec compliance

This page is the canonical compliance reference for the library: it tracks every
normative MUST/SHOULD requirement of [JSON:API 1.1](https://jsonapi.org/format/1.1/)
and records how — and whether — the library satisfies it. Each row carries a
[status](#status-legend), names the implementing class, and names a test that
proves it. Read a row as "this requirement, this code, this proof". When a
requirement is intentionally unsupported the row says so and gives the rationale.

If you are an evaluator or auditor verifying conformance, this is the page to
read top to bottom. If you are building an API, the capability pages
([resources](resources.md), [fields](fields.md), [relations](relations.md), …)
are the better starting point — this ledger is the backstop they all point at.

> **Scope — format compliance only.** This document tracks
> **[JSON:API 1.1](https://jsonapi.org/format/1.1/) *format* compliance** — the
> normative MUST/SHOULD requirements of the media type and document structure,
> and how the library satisfies them. It is **not** an OpenAPI document and must
> not be conflated with OpenAPI schema generation (a separate concern, out of
> scope here). For the pre-1.0 stability caveat and install instructions, see
> [index.md](index.md).

## Status legend

The ledger uses one status discipline throughout. Every row resolves to exactly
one of these, and an `n/a` row always carries a rationale.

| Status | Meaning |
|---|---|
| ✅ test | Implemented **and** covered by a test tagged `#[Group('spec:<section>')]`. |
| 🟡 code | Implemented in code, no dedicated spec-tagged test yet. |
| ⬜ todo | Not yet implemented. |
| 🚫 n/a | Intentionally unsupported / out of scope (rationale given inline). |

### spec-group anchoring

Tests that assert a spec requirement are tagged `#[Group('spec:<section>')]`,
where `<section>` mirrors the slug of a [JSON:API 1.1](https://jsonapi.org/format/1.1/)
section (`spec:document-structure`, `spec:errors`, `spec:fetching-resources`,
`spec:sorting`, …). The section anchors in this ledger map one-to-one onto those
PHPUnit groups, so a row's claim is runnable: filter the suite to the group and
you re-run exactly the proof behind the row. The groups are shared vocabulary —
the core suite under [`tests/`](../tests/) and the worked
[music-catalog example](../examples/music-catalog/) both tag with the same
slugs, so a single `--group spec:sorting` run spans the unit proof in core and
the end-to-end proof in the example app.

### The example app as a live compliance witness

The [music-catalog example](../examples/music-catalog/) is more than
documentation scaffolding: every example test renders a real document and runs
it through `assertJsonApiSpecCompliant()`, which validates the output against the
vendored JSON:API 1.1 JSON Schema. So the snippets quoted across these docs are
not just "code that compiles" — they are **format-validated documents**. See, in
[`GettingStartedTest`](../examples/music-catalog/tests/GettingStartedTest.php):

```php
#[Group('spec:fetching-resources')]
final class GettingStartedTest extends MusicCatalogTestCase
{
    use AssertsSpecCompliance;

    #[Test]
    public function fetchingASingleAlbumReturnsASpecCompliantDocument(): void
    {
        // …
        self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
        $this->assertJsonApiSpecCompliant($response);
    }
}
```

The `assertJsonApiSpecCompliant()` helper comes from the
[`AssertsSpecCompliance`](../examples/music-catalog/tests/GettingStartedTest.php)
trait (a [testing](testing.md) utility). It needs the suggested
`opis/json-schema`; see [schema-validation.md](schema-validation.md) for how the
schema validation is wired.

## Document structure (`spec:document-structure`)

| Requirement | Status | Notes |
|---|---|---|
| Top-level `jsonapi` object with `version` member | ✅ test | `Schema\JsonApiObject`; defaults to version `1.1` via `JsonApiObject::VERSION`. `JsonApiObjectTest`. |
| `jsonapi.meta` is a free-form meta object, omitted when empty | ✅ test | `JsonApiObject::transform()` omits empty `meta`. `JsonApiObjectTest`. |
| Links: bare-string or link-object (`{href, …}`) forms | ✅ test | `Schema\Link\Link` / `LinkObject`. `LinkTest`, `LinkObjectTest`. |
| Link object members `href`, `rel`, `title`, `type`, `hreflang`, `meta` | ✅ test | `LinkObject` models all; empty members omitted. `LinkObjectTest`. |
| Link object `describedby` member | ✅ test | `LinkObject` carries an optional `?Link $describedby` emitted by `transform()`. `LinkObjectDescribedbyTest`. |
| Templated links (RFC 6570) | ✅ test | No dedicated `templated` member exists in JSON:API 1.1; a templated link is a plain string `href`, representable as-is. (Decision log, Link audit.) |
| Profile link object with keyword `aliases` | ✅ test | `Schema\Link\ProfileLinkObject`. `ProfileLinkObjectTest`. |
| Top-level `meta` member | ✅ test | `Response\MetaResponse` (meta-only document) and the `withMeta()` wither on every response render into top-level `meta`. `MetaResponseTest`, `DataResponseTest`. |
| Links containers (`DocumentLinks`, `ResourceLinks`, `RelationshipLinks`, `ErrorLinks`) | ✅ test | Construct-only `final readonly` extending `AbstractLinks`; custom relation keys allowed; pagination links accepted as plain `?Link`. `DocumentLinksTest`, `ResourceLinksTest`, `RelationshipLinksTest`. |
| Top-level `links` member wiring into a document | ✅ test | `withLinks(DocumentLinks)` on every response renders into top-level `links`. `DataResponseTest`. |
| `data` / `errors` / `meta` mutual exclusivity & required members | ✅ test | Each response value object emits exactly one primary member by construction — `DataResponse`→`data`, `MetaResponse`→`meta`, `ErrorResponse`→`errors` (coexistence is unconstructable in the type system); the request-side guard (`JsonApiRequest::validateTopLevelMembers`) is enforced by `RequestBodyParsingMiddleware` via `Negotiation\RequestValidator`. `DataResponseTest`, `MetaResponseTest`, `ErrorResponseTest`, `RequestBodyParsingMiddlewareTest`. |
| Resource objects (`type`, `id`, `attributes`, `relationships`, `links`, `meta`) | ✅ test | `Serializer\AbstractSerializer`/`SerializerInterface` (consumer extension point) + `Transformer\ResourceTransformer`. `AbstractSerializerTest`, `ResourceTransformerTest`. |
| Resource identifier objects (`type`, `id`/`lid`, `meta`) | ✅ test | `Schema\ResourceIdentifier` (construct-only `final readonly`); `fromArray()` validates `type` + at-least-one-of(`id`,`lid`) and throws the typed `ResourceIdentifier*` exceptions directly; `transform()` emits whichever of `id`/`lid`/`meta` are present. `ResourceIdentifierTest`. |
| Compound documents / `included` | ✅ test | `Transformer\ResourceTransformer` + `DocumentTransformer` build the `included` array with resource dedup (primary takes precedence) via the `Schema\Data\AbstractData` accumulator (the `Schema\Data\DataInterface` contract). `ResourceTransformerTest`, `DocumentTransformerTest`; end-to-end in `SparseFieldsetsAndIncludesTest`. |
| Whole-document structural conformance (top-level member rules, resource/identifier/relationship/error shapes, member-name patterns, `data` XOR `errors`, `id`-optional-on-create) | ✅ test (opt-in) | `Validation\DocumentValidator` validates a decoded document against the vendored JSON:API 1.1 JSON Schema (draft 2020-12, `opis/json-schema`), with separate request/response roots; violations carry the offending JSON pointer as `source.pointer`. `DocumentValidatorTest`, `VendoredSchemaProviderTest`, `Request`/`ResponseValidationMiddlewareTest`. **Opt-in** (dev/CI), via the two validation middleware; requires the suggested `opis/json-schema`. |
| Per-resource request body validation (schema-driven) | ✅ test | `Validation\SchemaCompiler` compiles a resource's field+constraint metadata into a per-type create/update JSON Schema, composed into `DocumentValidator`'s `$additionalSchemas`; `RequestValidationMiddleware` validates the body against it (opt-in, with correct `source.pointer`s). `SchemaCompilerTest`, `PerResourceValidationTest`. |

> **Validation as a compliance aid.** The dev/CI validators turn many of the
> `spec:document-structure` MUSTs above from "asserted by a hand-written unit
> test" into "asserted by the JSON:API JSON Schema itself." The
> [music-catalog example](../examples/music-catalog/) takes exactly this route
> via `assertJsonApiSpecCompliant()`, so its rendered documents are checked
> against the schema rather than against bespoke assertions. This is **not**
> wired into the core suite by default, so the core test run does not depend on
> `opis/json-schema` being installed. See [schema-validation.md](schema-validation.md).

## Errors (`spec:errors`)

| Requirement | Status | Notes |
|---|---|---|
| Error `source` object (`pointer`, `parameter`, `header`) | ✅ test | `Schema\Error\ErrorSource` models `pointer`, `parameter` and `header` (with `fromPointer`/`fromParameter`/`fromHeader` named ctors), each omitted from `transform()` when empty. `ErrorSourceTest`, `ErrorSourceHeaderTest`. |
| Error object members (`id`, `links`, `status`, `code`, `title`, `detail`, `source`, `meta`) | ✅ test | `Schema\Error\Error` (construct-only; each member omitted from `transform()` when empty). `ErrorTest`. |
| Error `links` (`about`, `type`) | ✅ test | `Schema\Link\ErrorLinks` (construct-only; `type` links de-duped by href). `ErrorLinksTest`. |
| Error document (top-level `errors` array) | ✅ test | `Response\ErrorResponse` (`fromErrors()`/`fromException()`) renders the top-level `errors` array via `ErrorDocument`; HTTP status derived from the errors. `ErrorResponseTest`, `AbstractErrorDocumentTest`. |
| Typed exception → HTTP status mapping | ✅ test | See [the typed-exception map](#typed-exception--http-status-map) below. The concrete `Exception\*` classes implement `JsonApiExceptionInterface` (`getErrors(): list<Error>`, `getStatusCode()`); status/code/title/detail are spec-compliance surface. `JsonApiExceptionTest`, `ExceptionErrorDetailTest`. |
| Uncaught throwables → JSON:API error response | ✅ test | `Middleware\ErrorHandlerMiddleware` (outermost) catches `JsonApiExceptionInterface` (own status/errors) and any other `\Throwable` (→ generic 500). Debug-gated 500: `detail`=message + per-error `meta.{exception,file,line,trace}` when `$debug` is on, redacted otherwise; optional PSR-3 logger. `ErrorHandlerMiddlewareTest`, `MiddlewareChainIntegrationTest`. See [errors-and-exceptions.md](errors-and-exceptions.md). |

### Typed exception → HTTP status map

The library ships **37** concrete exceptions in `Exception\` plus **2** in the
resource adapter namespaces (`Resource\Sort\UnsupportedSort` and
`Resource\Filter\UnsupportedFilter`), all implementing `JsonApiExceptionInterface`.
Each fixes its own HTTP status, error `code`, `title`, and (where meaningful) a
`source.pointer` — so a thrown exception renders a complete, correctly-statused
error document without any consumer mapping. The full catalogue and the throw
model live in [errors-and-exceptions.md](errors-and-exceptions.md); the
status→class grouping below is the compliance view.

| Status | Exceptions |
|---|---|
| `400` | `DataMemberMissing`, `FilterParamUnrecognized`, `InclusionUnrecognized`, `InclusionUnsupported`, `QueryParamMalformed`, `QueryParamUnrecognized`, `RelationshipTypeInappropriate`, `RequestBodyInvalidJson`, `RequestBodyInvalidJsonApi`, `RequiredTopLevelMembersMissing`, `ResourceIdInvalid`, `ResourceIdMissing`, `ResourceIdentifierIdInvalid`, `ResourceIdentifierIdMissing`, `ResourceIdentifierLidInvalid`, `ResourceIdentifierTypeInvalid`, `ResourceIdentifierTypeMissing`, `ResourceTypeMissing`, `SortParamUnrecognized`, `SortingUnsupported`, `TopLevelMemberNotAllowed`, `TopLevelMembersIncompatible` |
| `403` | `AdditionProhibited`, `ClientGeneratedIdNotSupported`, `ClientGeneratedIdRequired`, `FullReplacementProhibited`, `RemovalProhibited` |
| `404` | `RelationshipNotExists`, `ResourceNotFound` |
| `406` | `MediaTypeUnacceptable` |
| `409` | `ClientGeneratedIdAlreadyExists`, `ResourceTypeUnacceptable` |
| `415` | `MediaTypeUnsupported` |
| `500` | `ApplicationError`, `NoResourceRegistered`, `ResponseBodyInvalidJson`, `ResponseBodyInvalidJsonApi`, `Resource\Sort\UnsupportedSort`, `Resource\Filter\UnsupportedFilter` |

> The `500` rows are **server configuration errors**, not client errors:
> `UnsupportedSort`/`UnsupportedFilter` mean an adapter was handed a directive it
> cannot translate (a wiring mistake), and `NoResourceRegistered` means a type was
> dispatched with no registered resource. This count is generated from the source
> tree, not carried by hand — do not hard-code a stale total. The example app
> exercises the client-facing arms end-to-end: a missing resource raises
> `ResourceNotFound` → `404` in
> [`GettingStartedTest`](../examples/music-catalog/tests/GettingStartedTest.php),
> and a custom `PaymentRequired` (see
> [`PaymentRequired`](../examples/music-catalog/src/Exception/PaymentRequired.php))
> shows the same contract for a consumer-defined `402`.

## Fetching data (`spec:fetching-resources`, `spec:fetching-relationships`, `spec:fetching-data`)

| Requirement | Status | Notes |
|---|---|---|
| Fetch individual / collection resources | ✅ test | `FetchResourceOperation` + `Psr7ToOperationHandlerAdapter` (GET → operation → handler → `DataResponse` → PSR-7) end-to-end; `DataResponse::fromResource`/`fromCollection`. `Psr7ToOperationHandlerAdapterTest`, `DataResponseTest`; end-to-end in `GettingStartedTest`. |
| Fetch relationships / related resources | ✅ test | `FetchRelatedOperation`/`FetchRelationshipOperation` (dispatched by target shape) + `Response\RelatedResponse`/`IdentifierResponse`. `Psr7ToOperationHandlerAdapterTest`, `RelatedResponseTest`, `IdentifierResponseTest`; end-to-end in `RelatedEndpointsTest`. Relationship-endpoint documents also carry top-level `jsonapi`/`meta`/`links`. `RelationshipDocumentMetaTest`. See [related-endpoints.md](related-endpoints.md). |
| Polymorphic related endpoints (`MorphTo` / `MorphToMany`) | ✅ test | The to-one resolves its serializer from the related object via `RelationInterface::resolveSerializer`; the to-many renders mixed members through a `PolymorphicSerializer`. `PolymorphicTest` (favorites/library, `#[Group('spec:fetching-relationships')]`). See [relations.md](relations.md). |

## Inclusion of related resources (`spec:inclusion-of-related-resources`)

| Requirement | Status | Notes |
|---|---|---|
| `include` query parameter; compound-document `included` | ✅ test | Request-side `include` parsing (`JsonApiRequest`) **and** engine-side application: `Transformer\ResourceTransformer` honours `include`/default-included relationships and emits the deduped `included` array. `JsonApiRequestTest`, `ResourceTransformerTest`, `DocumentTransformerTest`; end-to-end in `SparseFieldsetsAndIncludesTest`. See [sparse-fieldsets-and-includes.md](sparse-fieldsets-and-includes.md). |

## Sparse fieldsets (`spec:sparse-fieldsets`)

| Requirement | Status | Notes |
|---|---|---|
| `fields[TYPE]` query parameter | ✅ test | Request-side parsing **and** engine-side application: `Transformer\ResourceTransformer` filters attributes/relationships by `isIncludedField()`. `JsonApiRequestTest`, `ResourceTransformerTest`; end-to-end in `SparseFieldsetsAndIncludesTest`. |
| Per-type sparse-fieldset participation | ✅ test | Each `FieldInterface` declares `isSparseField()` (opt out via `notSparseField()`); the transformer narrows attributes/relationships per `fields[type]`. `FieldTest`, `ResourceTransformerTest`. |

## Sorting (`spec:sorting`)

| Requirement | Status | Notes |
|---|---|---|
| `sort` query parameter parsing | ✅ test | `JsonApiRequest::getSorting()` parses the `sort` param (comma-separated, `-` prefix preserved) and throws `QueryParamMalformed` on a non-string value. `JsonApiRequestTest`. |
| `sort` allowed-fields enforcement | ✅ test | A schema declares sortable fields (`->sortable()`); `Resource\AbstractResource::allSorts()` derives the allowed `SortByField` set, and a `SortHandlerInterface` rejects unknown keys via the typed `UnsupportedSort` (500). `ArraySortHandlerTest`, `AbstractResourceTest`; end-to-end (incl. a computed `trackCount` sort) in `SortsTest`. See [sorts.md](sorts.md). |

## Pagination (`spec:pagination`)

| Requirement | Status | Notes |
|---|---|---|
| `page[…]` query parameter parsing | ✅ test | Raw `page[…]` access (`JsonApiRequest::getPagination()`) plus the strategy paginators `Pagination\{Page,Offset,FixedPage}Paginator` and the standalone `Pagination\CursorPaginator`, which read `page[…]` and produce `Page` value objects (absent/non-numeric params fall back to defaults). `tests/Pagination/PaginatorTest.php`. |
| Pagination links (`first`/`prev`/`next`/`last`) | ✅ test | The `Pagination\{PageBased,OffsetBased,FixedPage,CursorBased}Page` value objects emit `linkSet()` (built via `Transformer\Utils::getUri`, preserving unrelated query params) and `pageMeta()`; `DataResponse::fromPage()` renders them into top-level `links` + `meta.page`. **`CursorBasedPage` omits `last` by design** (no total count). `tests/Pagination/*PageTest.php`, `DataResponsePaginationTest`; end-to-end in `PaginationTest`. See [pagination.md](pagination.md). |

## Filtering (`spec:filtering`)

| Requirement | Status | Notes |
|---|---|---|
| `filter` query parameter (format-agnostic) | ✅ test | Request-side parsing implemented + tested (`JsonApiRequest::getFiltering()`/`getFilteringParam()`, `JsonApiRequestTest`). Execution is adapter-provided by design. |
| `filter` parameter shape / handling | ✅ test | A schema declares `filters()` as typed `FilterInterface` value objects; adapter `FilterHandlerInterface`s translate them and reject unregistered filters via the typed `UnsupportedFilter` (500). Core ships the reference `InMemory\ArrayFilterHandler`. `ArrayFilterHandlerTest`; end-to-end (incl. a custom `WithinRadius` filter) in `FiltersTest`. See [filters.md](filters.md). |

## CRUD (`spec:crud`, `spec:creating-resources`, `spec:updating-resources`, `spec:updating-relationships`)

| Requirement | Status | Notes |
|---|---|---|
| Create / update / delete resources | ✅ test | Hydration (`Hydrator\AbstractHydrator` + traits) **and** the operation/adapter wiring for POST/PATCH/DELETE (`Create`/`Update`/`Delete`Operation, dispatched by `Psr7ToOperationHandlerAdapter`). `*HydratorTraitTest`, per-operation tests; end-to-end in `WritesTest` (201 + `Location`, 200, 204). Consumer supplies the handler logic. See [hydrators.md](hydrators.md). |
| Create / update / delete relationships | ✅ test | `AddToRelationship`/`UpdateRelationship`/`RemoveFromRelationship`Operation hydrate by verb→`Mode`, honouring `cannotReplace`/`cannotAdd`/`cannotRemove` and throwing `FullReplacementProhibited`/`AdditionProhibited`/`RemovalProhibited` (403). `Psr7ToOperationHandlerAdapterTest`; end-to-end in `RelationshipMutationTest`. See [relationship-mutation.md](relationship-mutation.md). |
| Client-generated IDs on create (`id`) | ✅ test | `CreateHydratorTrait::hydrateIdForCreate()` + `validateClientGeneratedId()` (throws `ClientGeneratedIdNotSupported`/`ResourceIdInvalid`); non-string `id` rejected. `CreateHydratorTraitTest`; end-to-end (uuid playlist) in `IdsTest`. See [ids.md](ids.md). |
| Local identifiers (`lid`) on resource objects / resource identifiers (creation) | ✅ test | `ResourceIdentifier` carries `?id`/`?lid` (`fromArray()` requires `type` + at-least-one-of; `ResourceIdentifierTest`); a relationship referenced by `lid` hydrates through to the relationship hydrator (`CreateHydratorTraitTest`); the request exposes `getResourceLid()`. **Scope boundary:** accept/carry `lid` only — cross-document `lid`→resource *resolution* is **not** supported (🚫 n/a for resolution: out of scope for a storage-agnostic core). |

## Content negotiation (`spec:content-negotiation`)

| Requirement | Status | Notes |
|---|---|---|
| `Content-Type` / `Accept` handling; reject unknown media-type params | ✅ test | `JsonApiRequest::validateContentTypeHeader()`/`validateAcceptHeader()` (→ `MediaTypeUnsupported`/`MediaTypeUnacceptable`) plus the `Negotiation\RequestValidator`/`ResponseValidator` orchestrators. Only `ext`/`profile` are permitted media-type params, and the two rules differ (per spec): `Content-Type` rejects any other param → 415 (`MediaType::isValid()`); `Accept` 406s only when **every** `application/vnd.api+json` instance is parametrized — a single conforming instance is acceptable and the `q` weight is ignored (`MediaType::accepts()`). `JsonApiRequestTest`, `RequestValidatorTest`, `ResponseValidatorTest`. See [content-negotiation.md](content-negotiation.md). |
| `ext` parameter negotiation (415/406 on unsupported extensions) | ✅ test | `RequestValidator(string ...$supportedExtensions)` rejects an `ext` not in its supported set: 415 on `Content-Type`, 406 on `Accept`. **Empty supported set by default** (no extensions ship in 1.0). `RequestValidatorTest`, `ExtensionTest`. |
| Content negotiation as PSR-15 middleware | ✅ test | `Middleware\ContentNegotiationMiddleware(string ...$supportedExtensions)` runs header/ext negotiation + query-param validation, wraps the request in `JsonApiRequest`, and passes it down; rejections render via the error handler. Profiles flow through (advisory). `ContentNegotiationMiddlewareTest`, `MiddlewareChainIntegrationTest`. See [middleware.md](middleware.md). |
| Request body parsing as PSR-15 middleware | ✅ test | `Middleware\RequestBodyParsingMiddleware` forces an early JSON decode (malformed → `RequestBodyInvalidJson` → 400) and validates the top-level member rules (`data`/`errors`/`meta` both present → `TopLevelMembersIncompatible` → 400), delegating to `Negotiation\RequestValidator`; bodyless requests untouched. `RequestBodyParsingMiddlewareTest`. |

## Query parameters (`spec:query-parameters`)

| Requirement | Status | Notes |
|---|---|---|
| Reject unrecognized implementation-specific query params | ✅ test | `JsonApiRequest` validates query-param family names; an unknown family raises `QueryParamUnrecognized` (400). Filter/sort/include families surface their own typed rejections (`FilterParamUnrecognized`, `SortParamUnrecognized`, `InclusionUnrecognized`, all 400). `JsonApiRequestTest`, `RequestValidatorTest`. |

## Extensions and profiles (`spec:extensions-and-profiles`)

| Requirement | Status | Notes |
|---|---|---|
| `profile` media-type parameter parsed on `Content-Type`/`Accept` (+ `profile` query param) | ✅ test | `JsonApiRequest::getAppliedProfiles()`/`getRequestedProfiles()`/`getRequiredProfiles()` (space-separated URI lists). `JsonApiRequestTest`, `ExtensionTest`. |
| `ext` media-type parameter parsed | ✅ test | `JsonApiRequest::getAppliedExtensions()`/`getRequestedExtensions()`. `ExtensionTest`. |
| Server MUST ignore unrecognized profiles (never reject) | ✅ test | Profiles are advisory: `RequestValidator` never rejects on a profile; the response only applies server-registered profiles and silently drops the rest. `RequestValidatorTest::negotiateIgnoresUnrecognizedProfiles`, `ProfileApplicationTest::ignoresUnregisteredRequestedProfile`. |
| Applied profile advertised in response `Content-Type` `profile` param | ✅ test | `Response\AbstractResponse::toPsrResponse()` echoes applied profile URIs; sets `Vary: Accept`. `ProfileApplicationTest`, `DataResponsePaginationTest`. |
| Top-level `links.profile` carries applied profile URIs | ✅ test | Populated by the response render path from the applied-profile set. `ProfileApplicationTest`. |
| Profile abstraction + registry | ✅ test | `Schema\Profile\{ProfileInterface,AbstractProfile,ProfileRegistry}`; `finalizeDocument()` document hook; duplicate-URI registration throws `ProfileAlreadyRegistered`. `ProfileRegistryTest`, `AbstractProfileTest`. The example wires a `TimestampProfile` (see [`TimestampProfile`](../examples/music-catalog/src/Profile/TimestampProfile.php)); see [profiles.md](profiles.md). |
| Cursor-pagination profile (first consumer) | ✅ test | `Pagination\CursorPaginationProfile` (`…/ethanresnick/cursor-pagination/`); `CursorBasedPage` activates it, so cursor-paginated responses advertise it on `Content-Type` + `links.profile`. `DataResponsePaginationTest::cursorResponseOmitsLastAndAdvertisesTheCursorProfile`. |
| Extensions cannot be used unless server-supported (415/406) | ✅ test | See the content-negotiation `ext` row. No extension ships in 1.0; the negotiation path is wired and tested against the empty supported set. |

## Validation as a compliance aid

The opt-in structural validation is a first-class compliance tool, not just a
test convenience. Two pieces cooperate:

- **`Validation\DocumentValidator`** validates any decoded document (request or
  response) against the vendored JSON:API 1.1 JSON Schema (draft 2020-12, via
  `opis/json-schema`), with separate request and response schema roots. Every
  violation it raises carries the offending JSON pointer as `source.pointer`, so
  a request-schema failure renders as a spec-shaped `400` and a response-schema
  failure as `500`. (The semantic `422` belongs to the runtime
  [constraint validator](constraints.md), not this schema path.)
- **`Validation\SchemaCompiler`** compiles a resource's own field + constraint
  metadata into a per-type create/update JSON Schema, composed into the
  document validator. This narrows "is this a valid JSON:API document" to "is
  this a valid `albums` create body for *this* API."

Wire either through the request/response validation middleware (or call the
validator directly in a test). Both are **opt-in** — they require the suggested
`opis/json-schema` and are deliberately off in the default middleware stack so
the core has no hard dependency on a schema engine. The full how-to, including
the `VendoredSchemaProvider` and the testing helpers, is in
[schema-validation.md](schema-validation.md).

## Internal-class evidence (not user-facing capability)

Several rows above cite engine internals — `Transformer\ResourceTransformer`,
`Transformer\DocumentTransformer`, the `Schema\Data\AbstractData` accumulator, the
`@internal QueryParam` reader — as *proof* that a requirement is satisfied. They
are named here only as evidence; they are **not** part of the supported public
surface and are not capabilities you build against. Your extension points are
the resource, serializer, hydrator, relation, filter, sort, paginator, and
profile interfaces documented across the capability pages, plus the response
value objects in [responses.md](responses.md).

## Next / see also

- [schema-validation.md](schema-validation.md) — wiring the opt-in
  DocumentValidator/SchemaCompiler that backs the validation-as-compliance-aid
  story.
- [errors-and-exceptions.md](errors-and-exceptions.md) — the full exception
  catalogue and throw model behind the [typed-exception map](#typed-exception--http-status-map).
- [testing.md](testing.md) — the `AssertsSpecCompliance` trait and the other
  helpers the example app uses to validate every rendered document.
- [content-negotiation.md](content-negotiation.md) — the 415/406 rules and the
  empty extension set summarized in the negotiation rows.
