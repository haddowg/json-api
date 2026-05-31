# CLAUDE.md — executor playbook

Executor-facing playbook for `haddowg/json-api`. This file is read by future
Claude Code sessions (including after context compaction or session restart) to
keep work consistent. It is **not** consumer documentation — consumer docs are
produced in Phase 5 under `docs/`.

## Project orientation

`haddowg/json-api` is a modern, server-side JSON:API 1.1 library for PHP 8.3+.
It is a **derivative work** based on [woohoolabs/yin](https://github.com/woohoolabs/yin)
(MIT) — substantial portions of the codebase derive from yin — but it is **not a
fork**: there is no upstream tracking relationship and no commitment to yin's
public API. Always credit yin as the original work; never describe this package
as a "fork".

- Spec: [JSON:API 1.1](https://jsonapi.org/format/1.1/)
- Namespace: `haddowg\JsonApi\…`; minimum PHP 8.3
- The master plan and phase plans live in `docs/`; start at [`docs/PLAN.md`](docs/PLAN.md).

Pattern entries (value objects, exceptions, resources, hydrators, middleware,
etc.) are added to this file as each component kind is first built, starting in
Phase 1.

## Git conventions

### Conventional Commits (required)

Every commit message MUST follow [Conventional Commits](https://www.conventionalcommits.org/).
Commit messages and PR titles drive automated versioning and the changelog via
[release-please](https://github.com/googleapis/release-please).

Format: `type(optional scope): description`

Common types:

| Type | Use for | Version impact (pre-1.0) |
|------|---------|--------------------------|
| `feat:` | A new feature | minor |
| `fix:` | A bug fix | patch |
| `docs:` | Documentation only | none |
| `test:` | Tests only | none |
| `refactor:` | Neither fixes a bug nor adds a feature | patch |
| `chore:`, `ci:`, `build:` | Tooling / maintenance | none |

- Use the imperative mood ("add", not "added"/"adds").
- Signal a breaking change with `!` after the type/scope (e.g. `feat!:`) or a
  `BREAKING CHANGE:` footer. While the package is `0.x`, breaking changes bump
  the **minor** version.

### Pull requests

**PRs are squash-merged.** The squash commit takes the **PR title** as its
subject, so:

- The **PR title MUST be a valid Conventional Commit** (e.g.
  `feat: add cursor-based pagination`, `chore: bootstrap repository tooling`).
  It becomes the single commit on `main` and feeds release-please — a
  non-conforming title breaks versioning.
- The **PR description** reads as natural prose, as if pitched by an external
  contributor proposing the change — not a templated form. Do **not** use literal
  "What"/"Why" headings. Convey the purpose and motivation in a short paragraph
  (optionally a few bullets for notable points), without walking through
  implementation specifics — the diff is the record of how. Describe the change
  on its own terms: do **not** reference internal phases, the master plan, or
  this playbook; a reader of the public repo has no context for them.
- Individual commits on the branch need not be individually meaningful (they are
  squashed away), but should still use Conventional Commit messages for a clean
  in-progress history.

## Operational rules

These apply to all phases (expanded in Phase 1 from the master plan):

- **Single-threaded until a pattern is established.** Build the first instance of
  a component kind sequentially in the main worktree; write its pattern entry
  here before fanning out.
- **Batching** is eligible only once (a) the pattern entry exists, (b) one full
  instance is built, tested, and merged, and (c) remaining work is mechanical.
- **Parallel work uses git worktrees**, one per subagent; convergence (merging
  back) is sequential with CI green at each step.
- **Tests port/build file-by-file alongside their implementation** — never
  deferred to a bulk end-of-phase pass.
- **Consolidation review after every fan-out**, recorded in the phase decision log.

## Tooling

Run before pushing (CI enforces all three across PHP 8.3/8.4/8.5 × lowest/highest):

```bash
composer test       # PHPUnit (attributes only, no annotations)
composer phpstan    # PHPStan level 9
composer cs-check   # PHP-CS-Fixer, PER-CS 2.0
```

Tests asserting a spec requirement are tagged `#[Group('spec:<section>')]` — see
[`tests/README.md`](tests/README.md).

## Porting workflow (yin reference)

A read-only checkout of yin lives at `/tmp/yin` (re-clone with
`git clone --depth 1 https://github.com/woohoolabs/yin.git /tmp/yin` if absent).
Map yin paths to ours by dropping the `JsonApi` path segment — it is already in
our namespace prefix:

- `WoohooLabs\Yin\JsonApi\Schema\Link\Link` (`src/JsonApi/Schema/Link/Link.php`)
  → `haddowg\JsonApi\Schema\Link\Link` (`src/Schema/Link/Link.php`)
- test `…\Tests\JsonApi\Schema\…` (`tests/JsonApi/Schema/…`)
  → `haddowg\JsonApi\Tests\Schema\…` (`tests/Schema/…`)

Port source **and its test together**; the source is not "done" until its test
is green under the new API. Rewrite (don't skip) tests whose yin behaviour the
modernised API replaces, and note the rewrite in the phase decision log.

## Type system principles

Default to PHPStan generics (`@template`) on **consumer-visible** types that
carry a parametric payload — `Page<T>`, `DataResponse<T>`, `Field<T>`,
`OperationHandler<TOperation>`, registry lookups (`class-string<T>` → narrowed
return). Skip generics on internal types, on PSR-* boundary types, and where
`instanceof`/`match` already narrows just as well. Apply at port time, not as a
retroactive sweep. Full rationale in `docs/PLAN.md`.

```php
// Generic — consumer sees T flow through:
/** @template T of object */
final readonly class DataResponse { /** @param T $data */ public function __construct(public object $data) {} }

// Non-generic — internal, instanceof narrows fine:
final readonly class JsonApiObject { /* no template */ }
```

## Modernisation patterns

Each entry is a paragraph + minimal sketch. Add an entry the first time a
component kind is ported; replace it (with a one-line decision-log note) if a
later port reveals a better pattern.

### Value objects / data classes

Leaf data types (`JsonApiObject`, `ErrorSource`, `Link`, …): `final readonly
class` with **public promoted constructor properties and no getters** — the
readonly property *is* the accessor. Use **named constructors** (static factory
methods returning `self`) for alternate construction forms instead of multi-form
constructors or optional-arg soup. Leaf VOs are **construct-only**: drop yin's
mutating setters (`setMeta`, `setLink`, …); the fluent `with…` surface belongs on
the response value objects, not here. `meta` stays a plain `array<string, mixed>`
(`[]` = omit); other absent structured members are nullable (`null` = omit). A VO
that appears in JSON output carries an `@internal transform(): array<…>` method
(properly typed for level 9) which the serialization engine calls. Make the class
`final` unless yin subclasses it (e.g. `Link` is extended by `LinkObject`, so it
is not `final` and its `transform()` return type is the union `string|array` that
subclasses covariantly narrow).

```php
final readonly class ErrorSource
{
    public function __construct(public string $pointer, public string $parameter) {}

    public static function fromPointer(string $pointer): self { return new self($pointer, ''); }

    /** @internal @return array<string, string> */
    public function transform(): array { /* omit empty members */ }
}
```

#### Links containers (variant)

Keyed link maps (`AbstractLinks` and its subclasses `ErrorLinks`,
`DocumentLinks`, `ResourceLinks`, `RelationshipLinks`) follow the value-object
pattern with two adjustments. (1) The base is `abstract readonly class`; every
subclass is `final readonly` — a readonly class may only be extended by another
readonly class, and PHPStan's `class.nonReadOnly` rule enforces it (even
anonymous test subclasses must be `new readonly class … extends AbstractLinks`).
(2) They are **construct-only**: links arrive through the constructor (drop yin's
`setLink`/`addType`/`setBaseUri` mutators), `null` entries are filtered out so an
absent relation is simply not in the map, and named constructors
(`ErrorLinks::withBaseUri(...)`) cover yin's alternate `create*` forms. Arbitrary
relation keys are allowed (the spec permits custom link relations). In
`transform()`, build any nested list separately and assign it once rather than
appending into the `mixed` result of `parent::transform()` (avoids
`offsetAccess.nonOffsetAccessible` at level 9).

### Exceptions

The typed exception hierarchy replaces yin's `ExceptionFactory` /
`ErrorDocument`-building exceptions. The `JsonApiException` interface
(`extends \Throwable`) is the contract: `getErrors(): list<Error>` exposes the
error **data** and `getStatusCode(): int` the HTTP status — exceptions carry
data, never a built document (the serialization layer assembles it).
`AbstractJsonApiException extends \Exception implements JsonApiException` takes
`(string $message, int $statusCode)`, forwards both to `parent::__construct()`
(so `getCode()` mirrors the status), stores the status in a
`private readonly int`, and surfaces it via `getStatusCode()`; it leaves
`getErrors()` abstract. Each concrete exception is a `final class` whose
constructor takes the same domain args as yin's factory method, promotes them as
`public readonly` properties, builds the human message inline, and implements
`getErrors()` returning freshly-built `Error` VOs via named args. yin's error
`detail` often differs from the thrown message (e.g. "…is not supported!" vs
"…is not supported by the endpoint!"), so spell out the literal `detail:` string
to match yin; use `detail: $this->getMessage()` only where yin's detail is
identical to the message. Preserve yin's status
codes, `code`, `title`, `detail`, and `source`/`meta` verbatim — these are
spec-compliance surface (including yin's existing typos, kept for fidelity).
Decouple from the not-yet-built request layer: body-invalid exceptions accept
the already-extracted data (raw/decoded body, validation-error list) rather than
a PSR message. Global classes are referenced as `\Exception` inline (the CS
config disables `global_namespace_import`), not imported.

```php
final class ResourceNotFound extends AbstractJsonApiException
{
    public function __construct() { parent::__construct('The requested resource is not found!', 404); }

    public function getErrors(): array
    {
        return [new Error(status: '404', code: 'RESOURCE_NOT_FOUND', title: 'Resource not found', detail: $this->getMessage())];
    }
}
```

### Requests

The request layer is the one place the readonly-everywhere default is **deliberately
dropped**. `JsonApiRequestInterface extends \Psr\Http\Message\ServerRequestInterface`
and adds the JSON:API parsing/validation surface; `AbstractRequest implements
ServerRequestInterface` (the interface is declared on the abstract base, not only on
the concrete class — required so the PSR-7 wither methods can covariantly return
`static`) and **composes** a wrapped `ServerRequestInterface`, delegating every PSR-7
method to it. Wither methods follow `$self = clone $this; $self->serverRequest =
$this->serverRequest->with…(); return $self;` — the wrapped request is replaced on a
clone, never mutated in place, so the value-object immutability contract holds at the
use site even though the class is **not** `readonly` (clone-then-assign and the lazy
per-group query-param caches both forbid `readonly` properties). `JsonApiRequest`
lazily parses and memoizes each query-param group (`fields`/`include`/`sort`/`page`/
`filter`/`profile`) and nulls the relevant cache when the corresponding header or
query param is replaced. Two modernisations replace yin's collaborators: (1) the
`ExceptionFactory` is gone — every `$exceptionFactory->create…()` becomes a direct
`throw new TypedException(...)`; (2) the `Deserializer` is gone — `getParsedBody()`
prefers the PSR-7 parsed body and otherwise decodes the raw body inline with
`\json_decode($raw, true, 512, \JSON_THROW_ON_ERROR)`, wrapping `\JsonException` in
`RequestBodyInvalidJson`. Tests build requests with `nyholm/psr7` (+ `withParsedBody()`
for JSON:API bodies) rather than a serializer.

```php
interface JsonApiRequestInterface extends ServerRequestInterface { /* validate*, get* parsing */ }

abstract class AbstractRequest implements ServerRequestInterface
{
    public function __construct(protected ServerRequestInterface $serverRequest) {}
    public function withMethod(string $method): static { $self = clone $this; $self->serverRequest = $this->serverRequest->withMethod($method); return $self; }
}
```

#### Hydrator relationship value objects (early port)

`Hydrator\Relationship\ToOneRelationship` / `ToManyRelationship` were ported ahead of
the Hydrator round because `JsonApiRequest::getTo{One,Many}Relationship()` returns
them. They follow the leaf-VO convention — `final readonly`, public promoted
properties, no simple getters (`$rel->resourceIdentifier(s)` is the accessor) — keeping
only the *computed* helpers (`isEmpty()`, `getResourceIdentifierTypes()/Ids()`).
`null`/`[]` data means "clear the relationship" (`isEmpty() === true`). The full
Hydrator pattern entry lands with the Hydrator round.

### Paginators (request-side)

The request-side pagination parsers (`Request\Pagination\{Page,Offset,Cursor,
FixedPage,FixedCursor}BasedPagination`) are leaf VOs: `final readonly`, public
promoted properties, no getters (`$pagination->page`/`->size`/`->offset`/`->limit`/
`->cursor` is the accessor). Each has a named constructor `fromPaginationQueryParams(
array $params, …defaults): self` that reads the raw `page[…]` map (from
`JsonApiRequestInterface::getPagination()`). Integer extraction **silently falls back to
the default** when the param is absent or non-numeric (`isset && \is_numeric ? (int) … :
$default`) — this matches yin's `Utils::getIntegerFromQueryParam`, which never threw, so
no exception is raised here (yin injected an `ExceptionFactory` into the factory but
never used it — dropped). The link-building statics `getPaginationQueryParams()` /
`getPaginationQueryString()` are retained (the Schema-side link-provider traits consume
them). `PaginationFactory` is a `final readonly` wrapper over the request exposing
`create*Pagination(...defaults)`. **Phase-2 note:** these fold into a unified `Page`
value object — each class carries a `// TODO(phase-2)` and the link-emission/profile
side of the paginator pattern is finalised then. The **link-emission side**
(`Schema\Pagination\{Page,Offset,Cursor,FixedPage,FixedCursor}BasedPaginationLinkProviderTrait`
+ `PaginationLinkProviderInterface`) is ported as **instance-method traits** (abstract
`getTotalItems()`/`getPage()`/… hooks) that build first/prev/next/last/self links via the
`@internal Transformer\Utils::getUri` helper (the one method of yin's root `Utils` that is
actually needed; the rest stays unported). These traits + the interface are also Phase-2
`// TODO`-marked: they fold into `Page` and the interface is slated for deletion then.

### Negotiation (validators)

`Negotiation\RequestValidator` / `ResponseValidator` are thin, **stateless** `final
class`es (no-arg constructors — yin's `SerializerInterface`/`ExceptionFactoryInterface`/
`$includeOriginalMessageInResponse` are all gone). They orchestrate validation but own
almost no logic: `RequestValidator` delegates straight to the request
(`negotiate()` → `validateContentTypeHeader()`+`validateAcceptHeader()`,
`validateQueryParams()`, `validateTopLevelMembers()`, and `validateJsonBody()` simply
calls `getParsedBody()` to surface `RequestBodyInvalidJson`). `ResponseValidator`
validates the response `Content-Type` (profile-only media-type params, mirroring the
request rule) and lints the body with inline `\json_decode(...JSON_THROW_ON_ERROR)` →
`ResponseBodyInvalidJson` (empty body = OK). **Phase-1 trim:** all JSON-schema body
validation (yin's `validateJsonApiBody`, `RequestBodyInvalidJsonApi`/`Response…`, the
bundled `json-api-schema.json` + `justinrainbow/json-schema`) is **deferred** — header
negotiation + JSON well-formedness only. yin's `AbstractMessageValidator` was **not
ported as a class**: once schema validation is removed nothing is genuinely shared
between request and response linting, so its remnants were folded into the two
validators rather than leaving an empty base. The JSON:API media-type-parameter rule
(`application/vnd.api+json` may only carry a `profile` parameter — yin-faithful, `ext`
not yet handled) lives in one place, `@internal Request\MediaType::isValid()`, consumed by
both `JsonApiRequest`'s Content-Type/Accept validation and `ResponseValidator`'s
Content-Type validation.

### Hydrators

`HydratorInterface::hydrate(JsonApiRequestInterface $request, mixed $domainObject):
mixed` is the request→domain contract (yin's `ExceptionFactory` arg is gone — typed
exceptions throw directly). `AbstractHydrator` composes the three **instance-method**
traits (`HydratorTrait` core + `CreateHydratorTrait` + `UpdateHydratorTrait`; no
`static`, call sites use `$this->`) and dispatches on the HTTP method (POST → create,
PATCH → update), then runs a `validateDomainObject()` hook. Concrete hydrators implement
the abstract hooks — `getAcceptedTypes()`, `getAttributeHydrator()`,
`getRelationshipHydrator()`, `setId()`, `generateId()`, `validateClientGeneratedId()`,
`validateRequest()` — so the contract stays **implementable by composition** (the traits
are an inheritance convenience, not a requirement). Relationship cardinality is checked
by reflecting the hydrator callable's 2nd-parameter type-hint and comparing it (`to-one`/
`to-many`) against the parsed `ToOneRelationship`/`ToManyRelationship`; a mismatch throws
`RelationshipTypeInappropriate`. **Decoded-JSON boundary:** request body members
(`type`/`id`/`attributes`/`relationships`/relationship `data`) arrive as `mixed`; guard
with `\is_string`/`\is_array` before use (a non-string `type`/`id` is malformed → throw
the typed exception), and bridge a JSON object to `array<string, mixed>` with an inline
`@var` only at the point it is handed to `ResourceIdentifier::fromArray()`.

> **`lid` (JSON:API 1.1 local IDs) is supported at the data-model level** (added beyond
> yin, which has none). `ResourceIdentifier` carries `?id` + `?lid` and `fromArray()`
> requires `type` + at-least-one-of(`id`,`lid`); a relationship referencing a not-yet-created
> resource by `lid` therefore parses and reaches the relationship hydrator with
> `->resourceIdentifier->lid` set and `->id` null (no hydrator logic change — it flows through
> `createRelationship()`→`ResourceIdentifier::fromArray()`). A resource created with a `lid`
> still receives a server-generated `id` (`lid` is a document-local handle, never the id);
> the request exposes it via `getResourceLid()`. **Not** implemented: cross-document `lid`
> *resolution* (mapping a `lid` to a freshly-created resource within one request) — that
> belongs with the post-1.0 Atomic Operations extension.

### Resources (serializer extension point)

`Schema\Resource\ResourceInterface` is the primary **consumer** extension point: it maps a
domain value to a JSON:API resource (`getType`/`getId`/`getMeta`/`getLinks`/`getAttributes`/
`getRelationships`/`getDefaultIncludedRelationships`). It is **not** generic — the serialized
value is `mixed` (a resource may describe an object, an array, or any representation; yin's
own tests pass arrays), so no `@template` is imposed. `getAttributes()`/`getRelationships()`
return maps of `callable(mixed, JsonApiRequestInterface, string): mixed|AbstractRelationship`.
The two lifecycle methods `initializeTransformation()`/`clearTransformation()` are
`@internal` (driven by the transformer, not consumers) even though the interface is public.
`AbstractResource` is the convenience base; the contract is implementable by composition.

### Relationships (serialization-side)

`Schema\Relationship\{AbstractRelationship, ToOneRelationship, ToManyRelationship}` are the
**output** relationships a resource emits (distinct from the construct-only
`Hydrator\Relationship\*` input VOs). They are consumer-facing and **mutable** (fluent
`setData()`/`setLinks()`/`setMeta()`/`omitDataWhenNotIncluded()`), because a resource builds
them up per request. `transform()` is `@internal` (the engine calls it) and carries the
inclusion/dedup decision tree verbatim from yin.

### Serialization engine & internal documents (`@internal`)

`Transformer\*` (`DocumentTransformer`, `ResourceTransformer`, the `*Transformation` pass-state
objects, and the folded `TransformerTrait`) plus `Schema\Document\*` (the `Abstract*Document`
hierarchy + `ErrorDocument` + their interfaces) are **`@internal`, mutable, per-pass/per-request**
machinery — never the consumer surface (consumers use resources + the forthcoming response value
objects). They mirror the `Schema\Data` accumulator decision: not `readonly`. The engine is
**serializer-free** — transformations return PHP **arrays**; JSON encoding lives in the response
layer, so no `json_encode`/`SerializerInterface` appears here. The spec-sensitive logic
(compound-document `included`, sparse fieldsets, included-resource dedup) is ported verbatim and
guarded by the ported `ResourceTransformerTest`/`DocumentTransformerTest`. `TransformerTrait`/
`Utils` were root-level in yin; `TransformerTrait` is folded into `Transformer\`, and `Utils` was
**not** ported (its only remaining consumer, `Utils::getUri`, is the Phase-2 pagination
link-providers; `getIntegerFromQueryParam` is already inlined in the pagination parsers).
`AbstractSimpleResourceDocument` is intentionally **not** ported (recorded footgun).

### Response value objects (public API) & `ServerInterface`

`Response\{DataResponse, MetaResponse, ErrorResponse, RelatedResponse, IdentifierResponse}`
are the **public** "return a JSON:API response" surface (consumers never touch documents).
They extend `Response\AbstractResponse` and follow the **clone-then-assign** immutability
pattern (NOT `readonly`, like `AbstractRequest`): `protected` document-level members (`meta`,
`links`, `jsonApi`, `headers`, `encodeOptions`) with fluent `with…()` withers returning
`static`; the response-specific payload (data+resource, error list) is `private readonly` and
**not** withable. Construction is via **named constructors** (`DataResponse::fromResource()`/
`fromCollection()` — single vs collection is explicit, never inferred from `is_iterable`;
`ErrorResponse::fromErrors()`/`fromException()`). Rendering: `render()` (abstract) builds the
body array + status via the engine and returns an `@internal Response\Internal\RenderedDocument`;
the `final toPsrResponse(ServerInterface, ServerRequestInterface)` wraps a non-JSON:API request
in `JsonApiRequest`, `\json_encode`s the body with `\JSON_THROW_ON_ERROR` passed **inline**
(so PHPStan narrows to `string` — never a `(string)` cast), and builds the PSR-7 response via
the server's PSR-17 factories with `Content-Type: application/vnd.api+json`. Each response builds
a **concrete `@internal` document** the response-VO layer adds on top of yin's abstract ones:
`SingleResourceDocument`/`CollectionDocument`/`MetaDocument` (carry jsonapi/meta/links via ctor);
`ErrorResponse` reuses the existing concrete `ErrorDocument` and takes its HTTP status from
`getStatusCode()`. `ServerInterface` (`Server\`) is the minimal Phase-1 placeholder the render
path reads: `baseUri()`, `jsonApiVersion()`, `defaultMeta()`, `encodeOptions()`, plus PSR-17
`responseFactory()`/`streamFactory()` (the kickoff "minimal Server" + the two factories needed to
actually emit PSR-7). The concrete `Server` (with a resource registry) is Phase 4.5; here a
test `StubServer` (nyholm PSR-17) stands in.

### Operations (public API)

`Operation\JsonApiOperation` is the verb-agnostic contract (`target(): Target`,
`queryParameters(): QueryParameters`, `context(): OperationContext`); there is **one
`final readonly` class per verb** (`FetchResource`/`Create`/`Update`/`Delete`/`FetchRelationship`/
`FetchRelated`/`UpdateRelationship`/`AddToRelationship`/`RemoveFromRelationship`Operation) so each
carries exactly its fields — the five with a request body also expose `body(): JsonApiRequestInterface`.
The three shared VOs are `final readonly`: `Target` (`type` + optional `id`/`relationship` +
`isRelationshipEndpoint` flag distinguishing `…/relationships/x` from the related `…/x`),
`QueryParameters` (parsed `fields`/`includes`/`sort`/`filter`/`pagination`, built via
`fromRequest()`), and `OperationContext` (public `server`; **nullable** `httpRequest()` — `null`
for programmatically-dispatched operations, so handlers must null-check). `OperationHandler`
(`handle(JsonApiOperation): DataResponse|MetaResponse|RelatedResponse|IdentifierResponse|ErrorResponse`)
is the recommended consumer handler — PSR-7-free; reach the request via `context()->httpRequest()`
only when genuinely needed. `Psr7ToOperationHandlerAdapter` (`final readonly`, PSR-15
`RequestHandlerInterface`) reads the `Target` from the `Target::class` request attribute (routing
supplies it in Phase 3; a missing Target renders a 500 `ErrorResponse`, not a throw), picks the
operation by a fixed **HTTP-method × target-shape `match`** dispatch table, invokes the handler, and
renders the response VO via `toPsrResponse()`. No generics — operations carry concrete fields and the
handler returns a fixed union. (Adds `psr/http-server-handler` for PSR-15.)

### Profiles (public API)

`Schema\Profile\ProfileInterface` is the consumer extension point for JSON:API 1.1 profiles:
`uri(): string` (the canonical URI matched against the negotiated `profile` parameter),
`keywords(): list<string>` (the member/link/query-param names the profile reserves — introspection
only, does not gate negotiation), and `finalizeDocument(array $body, JsonApiRequestInterface): array`
(a document-finalisation hook run once per **applied** profile during render, after the body array is
built and before encoding). `AbstractProfile` is the convenience base (default `keywords()` → `[]`,
`finalizeDocument()` → identity); the contract is implementable by composition. **Profiles are
advisory** — the spec says a server MUST *ignore* any profile it does not recognize, so an
unrecognized profile is never an error (contrast extensions). `ProfileRegistry` is a per-instance,
injected, eager **simple map keyed by URI** (`register`/`has`/`get`/`all`); duplicate-URI
registration throws `ProfileAlreadyRegistered` (a `\LogicException`, **not** a `JsonApiException` —
it is a wiring bug, never an error document). The registry is reached via `ServerInterface::profiles()`
and folds into the broader Phase-4.5 `Server` registry. Profile *application* lives in the response
layer (see below), not on the profile.

```php
final class CursorPaginationProfile extends AbstractProfile
{
    public const string URI = 'https://jsonapi.org/profiles/ethanresnick/cursor-pagination/';
    public function uri(): string { return self::URI; }
    public function keywords(): array { return ['page[size]', 'page[after]', 'page[before]']; }
}
```

#### Profile application & `ext` negotiation

Applied-profile resolution and emission live on `Response\AbstractResponse`: `appliedProfiles()`
intersects the request's requested/required profile URIs with the server's registered profiles
(unrecognized ones dropped), `toPsrResponse()` then runs each applied profile's `finalizeDocument()`,
records the URIs in top-level `links.profile`, echoes them in the `Content-Type` `profile` parameter,
and sets `Vary: Accept`. A response subtype extends `appliedProfiles()` to add its own (e.g.
`DataResponse` prepends a paginated `Page`'s profile **only when the server has registered it** —
a page never advertises an unregistered profile, and the registered instance is the one applied).
**`ext` negotiation** is parsed but not
dispatched: `Request\MediaType::isValid()` accepts **both** `ext` and `profile` as the only permitted
media-type parameters (and `MediaType::split()` cuts a header into instances quote-aware so a comma
inside a quoted value doesn't fragment it); the request exposes `getRequestedExtensions()`/
`getAppliedExtensions()`; `Negotiation\RequestValidator(string ...$supportedExtensions)` rejects an
unsupported `ext` (415 on Content-Type, 406 on Accept) against its supported set (empty by default).
This is the hook a post-1.0 Atomic Operations `ext` plugs into.

### Pagination (`Paginator` + `Page`)

Replaces yin's `PaginationLinkProviderInterface` + collection-side trait pattern (deleted). Two
halves: **strategy** and **value object**. A `Pagination\Paginator` strategy reads the `page[…]`
query params and produces a `Page`; the count-based strategies (`PagePaginator`, `OffsetPaginator`,
`FixedPagePaginator`) implement the `Paginator` interface (`paginate(request, items, totalItems):
Page`), while `CursorPaginator` is **standalone** (not a `Paginator`) because a cursor page has no
total and its `prev`/`next` boundaries are caller-supplied cursors, not derivable from a count.
Strategies are `final readonly`, fluent (`make()` + `with…()`), and silently default an absent/
non-numeric `page[…]` value (via `@internal Pagination\QueryParam::int`, the inlined yin rule).
The `Page` value objects (`PageBasedPage`, `OffsetBasedPage`, `FixedPagePage`, `CursorBasedPage`) are
`final readonly`, **generic** (`@template T`), extend `AbstractPage` (which makes them iterable via
`IteratorAggregate`, re-keying to int), and own link/meta emission: `linkSet(uri, queryString):
array<string, Link|null>` (a `null` value = that relation omitted) and `pageMeta(): array`. Links are
built with `@internal Transformer\Utils::getUri` so unrelated query params (`filter`, `sort`,
fieldsets) survive across pages. **`CursorBasedPage` omits `last` by design** (no count) and returns
the cursor profile from `profile(): ?ProfileInterface` (method-on-base, default `null` elsewhere) so
the response advertises it. The paginated render path is `DataResponse::fromPage($page, $resource)`;
plain collections stay `fromCollection()` and never carry pagination concerns.

```php
$page = PagePaginator::make()->withDefaultPerPage(20)->paginate($request, $items, $total);
return DataResponse::fromPage($page, $resource); // emits links.{first,prev,next,last} + meta.page
```
