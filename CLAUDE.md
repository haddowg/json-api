# CLAUDE.md — executor playbook

Maintenance playbook for `haddowg/json-api`, read by future Claude Code sessions
(including after compaction or restart). It records the **executor-facing**
decisions that do *not* belong in consumer docs — yin divergences, why a type
breaks a convention, what was deliberately not ported, PHPStan level-9 footguns.

Three places carry the rest, and this file links out rather than restating them:
the **public API** is documented under [`docs/`](docs/README.md), the **domain
language** in [`CONTEXT.md`](CONTEXT.md), and the **rationale for the big
architectural decisions** as ADRs under [`docs/adr/`](docs/adr/). Read the
consumer docs for the surface, an ADR for *why* a decision was made, and this file
for the finer-grained executor notes that fit none of those — yin divergences,
convention carve-outs, level-9 footguns.

## Project orientation

`haddowg/json-api` is a modern, server-side JSON:API 1.1 library for PHP 8.3+. It
is a **derivative work** based on [woohoolabs/yin](https://github.com/woohoolabs/yin)
(MIT) — substantial portions derive from yin — but it is **not a fork**: no
upstream tracking, no commitment to yin's public API. Always credit yin as the
original; never call this package a "fork". (See [ADR 0001](docs/adr/0001-derived-from-yin-not-forked.md).)

- Spec: [JSON:API 1.1](https://jsonapi.org/format/1.1/) · Namespace: `haddowg\JsonApi\…` · Min PHP 8.3
- Consumer docs: [`docs/README.md`](docs/README.md) · Domain language: [`CONTEXT.md`](CONTEXT.md) · Decision records: [`docs/adr/`](docs/adr/)

### Status

The library is **feature-complete** across the surface documented under `docs/`
(serialization, hydration, the fluent schema DSL, profiles, pagination,
middleware, optional validation). The remaining pre-1.0 work is a readiness pass:
a spec-compliance audit, a public-API surface review, a performance baseline, a
security review, and the release itself. Known not-yet-built work is the JSON:API
Atomic Operations extension (its seams are in place — see
[ADR 0011](docs/adr/0011-atomic-operations-deferred-seams-in-place.md)) and
attribute-driven hydrators.

## Git conventions

Follow `~/.claude/references/commits.md` and `~/.claude/references/pull-requests.md`.
Project-specific points that drive automated versioning via
[release-please](https://github.com/googleapis/release-please):

- Every commit message and **PR title** MUST be a valid
  [Conventional Commit](https://www.conventionalcommits.org/) (imperative mood). PRs
  are **squash-merged**, so the PR title becomes the single commit on `main` — a
  non-conforming title breaks versioning.
- While `0.x`, breaking changes (`!` or `BREAKING CHANGE:`) bump the **minor**.
- The PR description reads as natural prose pitched by an external contributor — no
  "What"/"Why" headings, no reference to internal planning or this playbook (a
  public reader has no context for them).
- When rebasing, use `git push --force-with-lease`.

## Tooling

Run before pushing (CI enforces all three across PHP 8.3/8.4/8.5 × lowest/highest):

```bash
composer test       # PHPUnit (attributes only, no annotations)
composer phpstan    # PHPStan level 9
composer cs-check   # PHP-CS-Fixer, PER-CS 2.0
```

Spec-requirement tests are tagged `#[Group('spec:<section>')]` — see
[`tests/README.md`](tests/README.md). The CS config disables
`global_namespace_import`, so reference global classes inline (`\Exception`,
`\json_decode`), never imported.

## Porting workflow (yin reference)

A read-only checkout of yin lives at `/tmp/yin` (re-clone with
`git clone --depth 1 https://github.com/woohoolabs/yin.git /tmp/yin` if absent).
Map yin paths to ours by dropping the `JsonApi` path segment (already in our
namespace prefix): `WoohooLabs\Yin\JsonApi\Schema\Link\Link` → `haddowg\JsonApi\Schema\Link\Link`.
Port source **and its test together** — the source is not "done" until its test is
green under the new API. Rewrite (don't skip) tests whose yin behaviour the
modernised API replaces.

## Type system principles

Default to PHPStan generics (`@template`) on **consumer-visible** types that carry
a parametric payload — `Page<T>`, `DataResponse<T>`, `Field<T>`,
`OperationHandler<TOperation>`, registry lookups (`class-string<T>` → narrowed
return). Skip generics on internal types, on PSR-* boundary types, and where
`instanceof`/`match` already narrows. Apply at port time, not as a retroactive
sweep.

## Modernisation conventions (shared)

These apply across every component; the per-component notes below only record
deviations and component-specific gotchas.

- **Leaf value objects** (`JsonApiObject`, `ErrorSource`, `Link`, identifiers,
  output VOs): `final readonly class`, public **promoted** properties, **no getters**
  (the readonly property *is* the accessor), **named constructors** for alternate
  forms. **Construct-only** — yin's mutating setters (`setMeta`/`setLink`/…) are
  dropped; the fluent `with…` surface lives on the response VOs, not here. `meta`
  is a plain `array<string, mixed>` (`[]` = omit); other absent structured members
  are nullable. A VO that appears in output carries an `@internal transform():
  array<…>` the engine calls. `final` unless yin subclasses it (e.g. `Link` is
  extended by `LinkObject`).
- **Clone-then-assign, not readonly** — the request and response layers are the
  *deliberate* exception to readonly-everywhere. Withers do
  `$self = clone $this; $self->x = $this->x->with…(); return $self;`; the classes
  are **not** `readonly` because clone-then-assign and the lazy per-group caches
  both forbid it. Immutability still holds at the use site.
- **yin's collaborators are gone** — `ExceptionFactory` → throw the typed exception
  directly; `Deserializer` / engine `SerializerInterface` → inline
  `\json_decode`/`\json_encode` with `\JSON_THROW_ON_ERROR` passed **inline** (lets
  PHPStan narrow to `string`, never a `(string)` cast).
- **Preserve yin's spec surface verbatim** — status codes, `code`, `title`,
  `detail`, `source`/`meta`, **including yin's existing typos**, are spec-compliance
  fidelity. yin's error `detail` often differs from the thrown message, so spell out
  the literal `detail:` string rather than reusing the message.
- **`@internal` machinery is never consumer surface** — `Transformer\*`,
  `Schema\Document\*`, `Response\Internal\*`, and every `…\Internal\…` helper.

## Component notes

Each entry: where it lives → the consumer doc → executor-only notes.

### Value objects & links containers
`src/Schema/…` (`JsonApiObject`, `ErrorSource`, `Link`, `ResourceIdentifier`) →
[architecture](docs/architecture.md), [concepts](docs/concepts.md). Follow the
leaf-VO convention. **Links containers** (`AbstractLinks` + `ErrorLinks`/
`DocumentLinks`/`ResourceLinks`/`RelationshipLinks`): base is `abstract readonly`,
every subclass `final readonly` (PHPStan `class.nonReadOnly` enforces it — even
anonymous test subclasses must be `new readonly class … extends AbstractLinks`).
Construct-only; `null` entries filtered out; arbitrary relation keys allowed. In
`transform()`, build any nested list separately and assign once (avoids
`offsetAccess.nonOffsetAccessible` at L9).

### Exceptions
`src/Exception` → [exceptions](docs/exceptions.md), [errors](docs/errors.md).
Typed hierarchy replaces yin's `ExceptionFactory`. `JsonApiException` (`extends
\Throwable`) is the contract: `getErrors(): list<Error>` + `getStatusCode(): int`
— exceptions carry **data**, never a built document. `AbstractJsonApiException(string
$message, int $statusCode)` forwards both to `parent::__construct()` so `getCode()`
mirrors the status. Body-invalid exceptions take the already-extracted data
(raw/decoded body, validation-error list) — **decoupled** from the request layer.

### Requests
`src/Request` → [content-negotiation](docs/content-negotiation.md),
[concepts](docs/concepts.md). Clone-then-assign / not-readonly (see shared notes).
`JsonApiRequestInterface extends ServerRequestInterface`; the interface is declared
on `AbstractRequest` (the base, not only the concrete) so PSR-7 withers covariantly
return `static`. `AbstractRequest` **composes** a wrapped `ServerRequestInterface`
and delegates every PSR-7 method. `JsonApiRequest` lazily memoizes each query-param
group (`fields`/`include`/`sort`/`page`/`filter`/`profile`) and **nulls the cache**
when the corresponding header/param is replaced. `getParsedBody()` prefers the PSR-7
parsed body, else inline-decodes and wraps `\JsonException` in `RequestBodyInvalidJson`.
The media-type-parameter rule (`application/vnd.api+json` may carry only `profile`/
`ext`) lives once in `@internal Request\MediaType::isValid()`, consumed by request
validation and `ResponseValidator`. Tests build requests with `nyholm/psr7`.

### Hydrators
`src/Hydrator` → [hydrators](docs/hydrators.md). `AbstractHydrator` composes three
**instance-method** traits and dispatches on HTTP method (POST → create, PATCH →
update) then runs `validateDomainObject()`. Relationship cardinality is checked by
**reflecting the hydrator callable's 2nd-parameter type-hint** and comparing
to-one/to-many; mismatch throws `RelationshipTypeInappropriate`. Decoded-JSON
boundary: body members arrive as `mixed` — guard with `\is_string`/`\is_array`
before use. The input relationship VOs (`src/Hydrator/Relationship/{ToOne,ToMany}Relationship`,
ported early) are **leaf VOs** (distinct from the mutable output relationships);
`null`/`[]` data = clear the relationship. **`lid`** (1.1 local IDs) is supported at
the data-model level beyond yin (`ResourceIdentifier` carries `?id` + `?lid`,
`fromArray()` requires `type` + at-least-one-of); cross-document `lid` *resolution*
is deferred to the post-1.0 Atomic Operations extension.

### Serializers & output relationships
`src/Serializer`, `src/Schema/Relationship` → [serializers](docs/serializers.md).
`SerializerInterface` (formerly `Schema\Resource\ResourceInterface`, renamed to
free `Resource` for the DSL) is **not generic** (the serialized value is
`mixed`) and **stateless** — yin's `initializeTransformation()`/`clearTransformation()`
are dropped, a single instance serializes many objects. The **output** relationships
(`Schema\Relationship\{AbstractRelationship,ToOne,ToMany}Relationship`) are the one
**mutable** Schema VO (a resource builds them per request); `transform()` is
`@internal` and carries yin's inclusion/dedup decision tree verbatim.

### Serialization engine & internal documents (`@internal`)
`src/Transformer`, `src/Schema/Document` → [architecture](docs/architecture.md).
Per-pass state lives **only** on the mutable `*Transformation` objects; the document
classes are **stateless** (no `initialize`/`clear` lifecycle). The engine is
**serializer-free** — transformations return PHP **arrays**, encoding lives in the
response layer. Spec-sensitive logic (compound `included`, sparse fieldsets, dedup)
is ported verbatim, guarded by `ResourceTransformerTest`/`DocumentTransformerTest`.
yin's root `Utils` was **not ported** except `@internal Transformer\Utils::getUri`;
`AbstractSimpleResourceDocument` was intentionally **not ported** (recorded footgun).

### Responses & ServerInterface
`src/Response`, `src/Server` → [responses](docs/responses.md), [server](docs/server.md).
Clone-then-assign / not-readonly. Construction via **named constructors** (single vs
collection is **explicit** — `fromResource()`/`fromCollection()` — never inferred
from `is_iterable`). `render()` builds the body array via the engine and returns an
`@internal RenderedDocument`; `final toPsrResponse()` wraps a non-JSON:API request in
`JsonApiRequest` and encodes with `\JSON_THROW_ON_ERROR` inline. **Profile application
and `ext` negotiation live here** (not on the profile): `appliedProfiles()` intersects
the request's requested/required URIs with the server's registered profiles, then
`toPsrResponse()` runs each `finalizeDocument()`, records `links.profile`, echoes the
`profile` media-type parameter, and sets `Vary: Accept`.

### Operations
`src/Operation` → [server](docs/server.md). One `final readonly` class **per verb**
(each carries exactly its fields; the five with a body expose `body()`). Shared VOs:
`Target`, `QueryParameters` (`fromRequest()`), `OperationContext` (public `server`;
**nullable** `httpRequest()` — `null` for programmatic dispatch, so handlers must
null-check). `OperationHandler` is PSR-7-free. `Psr7ToOperationHandlerAdapter` reads
the `Target` from the `Target::class` request attribute (a missing Target renders a
500, not a throw) and picks the operation by a fixed HTTP-method × target-shape `match`.

### Profiles
`src/Schema/Profile` → [profiles](docs/profiles.md). **Advisory** — the spec says a
server MUST *ignore* an unrecognized profile, so it is never an error (contrast
extensions). `ProfileRegistry` is a per-instance injected map keyed by URI;
duplicate-URI registration throws `ProfileAlreadyRegistered`, a `\LogicException`
(**not** a `JsonApiException` — a wiring bug, never an error document).

### Pagination
`src/Pagination` → [pagination](docs/pagination.md). **Replaced** yin's
`PaginationLinkProviderInterface` and the separate request-side paginator classes
(both **deleted** — there is no `Request\Pagination`). Count-based strategies
(`Page`/`Offset`/`FixedPage`Paginator) implement `Paginator`; `CursorPaginator` is
**standalone** (a cursor page has no total and its boundaries are caller-supplied).
`Page` VOs are `final readonly`, **generic** (`@template T`), iterable via
`AbstractPage`. `CursorBasedPage` **omits `last` by design** and returns the cursor
profile from `profile()` so the response advertises it. `@internal QueryParam::int`
is the inlined yin silent-default rule (absent/non-numeric `page[…]` → default,
never throws).

### Middleware
`src/Middleware` → [middleware](docs/middleware.md), [middleware-order](docs/middleware-order.md).
Constructor injection only (no service location, no select-server middleware). **The
parsed request flows by swapping it down the chain, not via an attribute** — the
first middleware to need it does `$r = $request instanceof JsonApiRequestInterface ?
$request : new JsonApiRequest($request);` (idempotent) and passes `$r` to the handler,
so the whole chain shares one memoized parse. The only request **attribute** read
anywhere is `Operation\Target::class`. Negotiation is **request-side only**.
`ErrorHandlerMiddleware` is **outermost**: it catches throwables → `ErrorResponse`,
**passes successful responses through unchanged**, and does **not** render consumer
VOs (the adapter does). Its debug-gated 500 puts `{exception, file, line, trace}` in
the **error object's `meta`** (the spec-faithful home — `source` is for request
locations).

### Validation (JSON Schema, optional/dev)
`src/Validation` → [validation](docs/validation.md). Backed by the **optional**
`opis/json-schema` (`require-dev` + `suggest`, **never** `require`). `DocumentValidator`
builds **one reusable** opis validator and validates against a synthetic composite
root `{ "allOf": [ {"$ref": <root>}, …$additionalSchemas ], "unevaluatedProperties":
false }`. `allOf` is the single extension point — **profile fragments** now and
**per-resource compiled schemas** are both just entries in `$additionalSchemas`.
Relocating the base root's `unevaluatedProperties` onto the composite is what lets a
fragment **extend** the allowed top-level members. Violations map to the **existing**
existing `Request`/`ResponseBodyInvalidJsonApi` exceptions — no new exception type.
The two middleware are **per-server opt-in** (the injected `DocumentValidator` makes
DI fail fast if opis is absent). **`SchemaCompiler`** turns a resource's field+
constraint metadata into a draft-2020-12 `stdClass` that **tightens** the base for one
type/context and drops into the same `$additionalSchemas` list (no validator API
change); `When`/`Custom` constraints are **skipped** (don't round-trip). Build nested
arrays and convert to `stdClass` once at the boundary (analysable at L9).

### Fluent schema — fields, constraints, relations
`src/Resource` → [resources](docs/resources.md), [fields](docs/fields.md),
[validation](docs/validation.md). An `AbstractResource` subclass satisfies **both**
`SerializerInterface` and `HydratorInterface` from one `fields()` list. **Fields are
mutable builders** (methods mutate and return `$this`) — *deliberately not* the
readonly-VO pattern, so a field reads as one fluent expression. **`Constraint` is
metadata only — the core never executes it** (each is a `final readonly` VO with a
create/update `Context`); execution belongs to the JSON Schema compiler or an adapter.
A framework-agnostic `@internal Accessor` reads/writes arrays and plain objects.
**Relations**: `Relation extends Field`; serializes via the related type's serializer
(resolved through the injected `SerializerResolver`), hydrates from the parsed
`Hydrator\Relationship\*` VOs. `BelongsToMany` pivot fields are **declare-only** in
1.0; `MorphTo` picks the serializer by the related object's `getType()`.

### Filters & sorts
`src/Resource/Filter`, `src/Resource/Sort` → [filters](docs/filters.md),
[sorts](docs/sorts.md), [adapters](docs/adapters.md). **VO metadata** (`Filter`/`Sort`
are just `key()` + accessors — **no `apply()`**); execution lives in adapter-provided
`FilterHandler`/`SortHandler` whose `query` arg is a templated `mixed` (no data layer
coupled in core). Mirrors the `Constraint` + translator split; there is **no generic
`Query` interface**. An unrecognised VO at a handler throws the typed
`UnsupportedFilter`/`UnsupportedSort` (a server-config error → **500**). Core ships
reference `InMemory\Array{Filter,Sort}Handler` for its own tests and as worked
examples — **not** a production query layer.

### Server & resource registry
`src/Server` → [server](docs/server.md). `Server` is an **immutable value** (`make()`
+ `with…()`/`register()` clone, and the cloned `ResourceRegistry`/`ProfileRegistry`
are cloned too so registration never leaks). Keeps the base `ServerInterface`
render contract **unchanged**. It implements PSR-15 `RequestHandlerInterface`
(`handle()` folds the middleware list over the inner handler via `@internal
MiddlewareDecorator`; an `OperationHandler` is auto-wrapped in the adapter) **and**
`SerializerResolver`. `ResourceRegistry` **is** the `SerializerResolver` injected into
resources; a missing type throws `NoResourceRegistered` (500), a duplicate type is a
`\LogicException` (wiring bug). `dispatch(JsonApiOperation)` invokes the handler
directly (no PSR-15 chain).

### Testing utilities & escape hatches
`src/Testing` → [testing](docs/testing.md). Shipped in the package autoload (**not**
dev-only — useful in consumer suites). Assertions + builders only; **no**
factories/fixtures/DB traits/HTTP clients. **Escape hatches**
([serializers](docs/serializers.md) / [hydrators](docs/hydrators.md)): drop to a
hand-written `Serializer`/`Hydrator` when field-walking isn't enough; register the
override alongside the Resource class (the registry resolves the override first,
falls back to the Resource for the other concern). A bare serializer+hydrator pair
with no Resource class still works exactly as the bare contracts do on their own.
