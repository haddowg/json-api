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
side of the paginator pattern is finalised then.

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
validators rather than leaving an empty base.

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

> **`lid` (JSON:API 1.1 local IDs) is NOT supported** — yin never implemented it, so the
> port doesn't either. Creating a resource accepts a client `id` (`validateClientGeneratedId`)
> or generates one (`generateId`); there is no `lid` path, and `ResourceIdentifier`
> requires `id`. Tracked as a spec-compliance gap (see `docs/spec-compliance.md`); a real
> `lid` implementation pairs naturally with the post-1.0 Atomic Operations work.
