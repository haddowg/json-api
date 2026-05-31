# Phase 4.5 — Fluent Schema DSL

## Goal & scope

Add a declarative, fluent **schema layer** on top of the resource (per-resource-type serializer) and hydrator port from Phase 1. A `Schema` declares a resource type's fields (attributes + relationships), filters, sorts, pagination, and validation constraints in a single class, using `make()`-style fluent builders. The schema **satisfies yin's `Resource` (serializer) and `Hydrator` contracts by default**, so consumers can register a single schema class and get serialization + hydration for free. Consumers who need more control over either concern register a custom resource and/or hydrator alongside the schema; the registry resolves overrides first and falls back to the schema.

This phase establishes the schema as **the recommended public surface** for the package at 1.0. The `Resource` and `Hydrator` contracts ported in Phase 1 remain first-class public API as documented escape hatches.

**Vocabulary note.** Throughout this document, "resource" refers to **yin's per-resource-type serializer class** (`AbstractResource` / `ResourceInterface`, ported in Phase 1) — *not* the JSON:API spec's "resource object" (the `{type, id, attributes, relationships}` thing in the document body, which is `ResourceObject` in the document namespace). When the spec sense is meant, this document says "resource object" explicitly.

**In scope:**

- `Field` contract and a split family of concrete field types (`Id`, `Str`, `Email`, `Url`, `Uuid`, `Slug`, `Ip`, `Boolean`, `Integer`, `Decimal`, `Date`, `DateTime`, `Time`, `ArrayList`, `ArrayHash`, `Map`)
- `Relation` family: `BelongsTo`, `HasOne`, `HasMany`, `BelongsToMany`, `MorphTo`
- `Filter` and `Sort` value-object contracts plus a small framework-agnostic vocabulary. Core ships **metadata only**; adapters ship handlers that translate the metadata into their data layer's native query operations. Same pattern as `Constraint` metadata + adapter translators.
- `Schema` abstract base implementing yin's `Resource` (serializer) and `Hydrator` contracts via its `fields()`
- `Server` (the per-API-version configuration root introduced in this phase, building on the Phase 1 placeholder) holding `[type => schema, ?resource-override, ?hydrator-override]`, the profile registry from Phase 2, base URI, JSON:API version, default `jsonapi.meta`, default paginator, encoding flag defaults, and **the middleware list** for one API surface. `Server` implements `Psr\Http\Server\RequestHandlerInterface`; `Server::handle($request)` runs its middleware chain. Multiple servers supported for API versioning.
- `Constraint` contract and a fixed structural vocabulary; constraint metadata is consumed by Phase 4 (JSON Schema compilation) and by framework adapters (Symfony bundle, etc.)
- `Constraint::context()` for create/update-context filtering, plus `requiredOnCreate()` / `requiredOnUpdate()` shortcuts and `onCreate(Closure)` / `onUpdate(Closure)` builders on every field
- Documentation that the schema is the recommended surface and `Resource` + `Hydrator` are escape hatches

**Out of scope:**

- Constraint **execution**. Core ships metadata only. The Phase 4 JSON Schema compiler consumes the metadata for structural validation; framework adapters consume the metadata for full request validation. Core never runs validators against data.
- Attribute-driven schema discovery (`#[ResourceType]`, `#[Attribute]`, `#[Relationship]`). Post-1.0 candidate; layers on top of the fluent DSL.
- Doctrine / database query handlers for filters and sorts. Filter/sort handlers live in the Symfony bundle; core ships filter/sort **metadata only** plus a reference array-backed handler for tests and worked examples. There is **no generic `Query` adapter interface in core** — adapters use their data layer's native query builder directly.
- Generators (`bin/console make:jsonapi:schema` equivalent). Framework-specific; lives in adapters.
- N+1 eager-load planning. Data-layer-aware; lives in adapters.
- Authorization / policy integration. Adapter concern.
- `HtmlString` / `RichText` field type. Skipped in 1.0 pending a concrete consumer.

## Prerequisites

- Phase 1 complete: yin's `Resource` and `Hydrator` contracts are clean enough that an abstract base class can implement them by walking a field list. Phase 1 acceptance criterion explicitly verifies this.
- Phase 2 complete: profile registry exists and can be folded into (or sit alongside) the new schema registry.
- Phase 3 complete: middleware suite is in place; the registry is constructor-injected wherever schemas need to be resolved.
- Phase 4 complete: the optional JSON Schema validation pipeline exists. This phase extends it to compile per-resource schemas from field constraints. Phase 4's plan must be amended at this phase's kick-off (or earlier — see Phase 4 plan corrections in the handover output) to defer the per-resource compilation to here.

## Kick-off

Before writing any implementation code:

1. Read `docs/phase-4-validation.md` — specifically its decision log and handover output — and reconcile against the current repository state.
2. **Locked but reviewable: namespace rename.** The existing `haddowg\JsonApi\Schema\*` subnamespaces (yin's document parts — `Schema\Document\*`, `Schema\Resource\*` for the serializer class, `Schema\Link\*`, `Schema\Error\*`, `Schema\Pagination\*`, `Schema\Relationship\*`) are renamed to `haddowg\JsonApi\Document\*` to free `Schema` as a clean home for the new fluent type. There is **no hard FQCN collision** — `haddowg\JsonApi\Schema\Schema` would be a valid class location — so the rename is for readability rather than necessity. Renaming is the current intent; if the maintainer prefers to keep the existing layout and live with `Schema\Schema`, that's revertible at this kick-off. If renaming, the rename executes as the first commits of this phase, before any new code is added; CI must be green at the end of the rename before any new component lands. Update `CLAUDE.md` pattern entries to reflect the new namespace.
3. Confirm the `Server` shape: a single value object holding schemas + profiles + resource/hydrator overrides + middleware list + URI/version/jsonapi.meta defaults, OR multiple composed values (e.g. a separate `SchemaRegistry`, `ProfileRegistry`, and `Server` that composes them)? Lean: single `Server` with internal sub-structures it exposes via accessors (`$server->schemas()`, `$server->profiles()`); avoids a proliferation of small types while keeping internals discoverable.
4. **Locked: filter/sort handler pattern.** Core ships `Filter` and `Sort` as value-object metadata; execution lives in adapter-provided handlers (e.g. `DoctrineFilterHandler` in the Symfony bundle). Mirrors the `Constraint` metadata + adapter-translator pattern. Reference array-backed handler ships in core for tests and worked examples. No generic `Query` interface in core.
5. Re-read this plan in full. Resolve every open question (and any new ones surfaced during the read) by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides (e.g. `AskUserQuestion` in Claude Code). Do not guess or silently defer. Record each answer in the decision log.
6. Revise the task list as needed and commit the plan revision as a single commit before starting implementation.

## Design overview

### The layering

```
Schema (default: satisfies yin's Resource + Hydrator contracts via its fields)
  ↓ optional override
Resource (custom serialization for one resource type — yin's per-type serializer)
  ↓ optional override
Hydrator (custom request → model logic for one resource type)
```

Each layer is independently usable. A consumer can:

- Register only a schema → schema serves both contracts (the 95% case).
- Register a schema + custom resource → schema serves hydration, custom class serves serialization.
- Register a schema + custom hydrator → schema serves serialization, custom class serves hydration.
- Register both overrides → schema is purely a declaration of fields, filters, sorts (for query handling), and constraints (for validation); resource + hydrator do the actual work.
- Skip the schema entirely → register a resource and hydrator directly. Works exactly as Phase 1 ships. Validation can't drive off field metadata in this case; the consumer wires their own.

### Fluent field DSL

```php
final class PostSchema extends Schema
{
    public static string $type = 'posts';

    public function fields(): array
    {
        return [
            Id::make()->uuid(),
            Str::make('title')
                ->sortable()
                ->required()
                ->minLength(1)
                ->maxLength(200),
            Slug::make('slug')
                ->requiredOnCreate()    // POST only; PATCH treats absent as "no change"
                ->maxLength(100),
            Email::make('contactEmail')
                ->maxLength(255),
            DateTime::make('publishedAt')
                ->sortable()
                ->readOnly()
                ->after(new \DateTimeImmutable('2020-01-01')),
            Integer::make('viewCount')
                ->readOnly()
                ->min(0),
            BelongsTo::make('author')
                ->type('users')
                ->required(),
            HasMany::make('comments')
                ->cannotEagerLoad(),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('slug')->singular(),
            WhereIdIn::make($this)->delimiter(','),
        ];
    }

    public function sorts(): array
    {
        // Sort is derived from fields that declared ->sortable(); this method
        // is for sorts that don't map directly to a single field.
        return [];
    }

    public function pagination(): ?Paginator
    {
        return PagePagination::make()
            ->withPageKey('number')
            ->withPerPageKey('size');
    }
}
```

### Constraint metadata

Each constraint is a readonly value object implementing the `Constraint` interface. Constraints carry a `Context` that says whether they apply on create requests, update requests, or both. Core never executes them; the JSON Schema compiler in Phase 4 translates the structural subset to JSON Schema; framework adapters translate the full set to native validator rules.

```php
interface Constraint
{
    public function context(): Context;
}

final readonly class Context
{
    public function __construct(
        public bool $onCreate = true,
        public bool $onUpdate = true,
    ) {}

    public static function always(): self { return new self(true, true); }
    public static function onlyCreate(): self { return new self(true, false); }
    public static function onlyUpdate(): self { return new self(false, true); }
}

final readonly class MaxLength implements Constraint
{
    public function __construct(
        public int $value,
        public Context $context = new Context(),
    ) {}

    public function context(): Context { return $this->context; }
}
```

### `Required` semantics

**Documented default:** `Required` means "must be non-empty if present." On POST (creating a resource), absence of a required field is also a failure (consistent with the JSON:API spec's expectation that creation provides initial values). On PATCH, JSON:API defines partial updates: absent fields mean "don't change," so absence is **not** a failure even on required fields; only an explicitly-supplied empty value fails.

`requiredOnCreate()` is the stricter form: must be present on POST, irrelevant on PATCH (typically paired with `readOnlyOnUpdate()` for create-once fields).

`requiredOnUpdate()` is rare but provided for symmetry: required when supplied on PATCH, irrelevant on POST.

This is the **package-wide convention** for `Required` semantics; it lives in `docs/validation.md` and is implemented uniformly by both the JSON Schema compiler and adapter translators.

### Filter / sort handler pattern

Filters and sorts are **value objects** describing intent. They carry no behaviour.

```php
// Core: metadata only
final readonly class Where implements Filter
{
    public function __construct(
        public string $key,
        public string $column,
        public string $operator = '=',
        public ?\Closure $deserialize = null,
        public bool $singular = false,
    ) {}

    public function key(): string { return $this->key; }
}

final readonly class WhereIn implements Filter
{
    public function __construct(
        public string $key,
        public string $column,
        public ?string $delimiter = null,
        public bool $singular = false,
    ) {}

    public function key(): string { return $this->key; }
}
```

Execution lives in a handler the adapter provides:

```php
// Symfony bundle (lives outside core)
final class DoctrineFilterHandler implements FilterHandler
{
    public function apply(Filter $filter, QueryBuilder $qb, mixed $value): void
    {
        match (true) {
            $filter instanceof Where    => $this->applyWhere($filter, $qb, $value),
            $filter instanceof WhereIn  => $this->applyWhereIn($filter, $qb, $value),
            $filter instanceof WhereHas => $this->applyWhereHas($filter, $qb, $value),
            // ...
            default => throw new UnsupportedFilter($filter),
        };
    }
    // ...
}
```

Same shape for `Sort`. The pattern is identical to `Constraint` + constraint translator, by design — three subsystems (constraints, filters, sorts), one integration story.

**Core ships a reference `ArrayFilterHandler` / `ArraySortHandler`** under `haddowg\JsonApi\Schema\Filter\InMemory` (and similar for sorts). They operate on PHP arrays via `array_filter` / `usort`. Used by the package's own test suite and shippable as a worked example for adapter authors. Not intended as a "production in-memory query layer" — just the small handler set needed to demonstrate and test the pattern.

**Why not a generic `Query` interface?** Considered and rejected. A generic interface that all adapters implement would either (a) be too narrow, forcing custom filters to invent their own abstractions, or (b) leak a relational worldview, making non-SQL adapters bend awkwardly. The value-object + handler split lets each adapter use its native query builder directly — Doctrine adapters use `Doctrine\ORM\QueryBuilder`, Elasticsearch adapters use whatever they use, no translation contract in the middle. The trade-off: a custom filter is only useful when there's a handler registered for it. Same trade-off as `Constraint::Custom`; accepted explicitly rather than hidden behind a generic interface that gives the false appearance of cross-adapter portability.

## Task list

### Namespace rename

- [ ] Rename `haddowg\JsonApi\Schema\*` to `haddowg\JsonApi\Document\*`. All Phase 1 code under that namespace moves; tests follow. This is the first commit(s) of this phase. No new code lands until the rename is in and CI is green.
- [ ] Update every internal reference, including PHPDoc and `CLAUDE.md` pattern entries.
- [ ] Confirm no external `use` statement is leaked through any public API signature; the rename is mechanical for consumers of the package (a search-and-replace) but the public surface should be searchable for any awkward type references that resist the move.

### Field contracts and base types

- [ ] `haddowg\JsonApi\Schema\Field\Field` interface — the common contract: `name()`, `column()`, serialization hooks, hydration hooks, constraint list accessor, read-only state accessors, sparse-fieldset participation, sortable flag.
- [ ] `haddowg\JsonApi\Schema\Field\AbstractField` — abstract base implementing the common fluent surface: `make()`, `readOnly()`, `readOnlyOnCreate()`, `readOnlyOnUpdate()`, `hidden()`, `notSparseField()`, `sortable()`, `serializeUsing()`, `deserializeUsing()`, `fillUsing()`, `extractUsing()`, plus the constraint-list machinery and `onCreate()` / `onUpdate()` context builders.
- [ ] Constraint context machinery: `Context` value object; `Constraint` interface; per-field append API. Each `required()`-family fluent method on a field appends the appropriate constraint with the appropriate `Context`.

### String-family fields

- [ ] `Str` — generic string. Fluent constraints: `minLength`, `maxLength`, `pattern`, `in`, `notIn`. Shortcuts: `email()`, `url()`, `uuid()`, `slug()`, `ip()` — each appends the corresponding format constraint to the constraint list, so `Str::make('contact')->email()` and `Email::make('contact')` produce identical metadata.
- [ ] `Email` — dedicated; defaults to validating email format. Fluent: `maxLength`, `strict()`.
- [ ] `Url` — dedicated. Fluent: `maxLength`, `allowedSchemes(string ...$schemes)`.
- [ ] `Uuid` — dedicated. Fluent: `version(int)`.
- [ ] `Slug` — dedicated; defaults to a sensible slug pattern. Fluent: `pattern()` (override default), `minLength`, `maxLength`.
- [ ] `Ip` — dedicated. Fluent: `v4()`, `v6()`, `both()` (default).

### Numeric fields

- [ ] `Integer` — JSON `type: integer`. Fluent: `min(int)`, `max(int)`, `multipleOf(int)`, `exclusiveMin(int)`, `exclusiveMax(int)`, `in(int[])`.
- [ ] `Decimal` — JSON `type: number`. Fluent: same vocabulary, `int|float` parameters.

### Boolean field

- [ ] `Boolean` — straight boolean. No type-specific constraint vocabulary beyond `required()`.

### Date/time fields

- [ ] `Date` — `YYYY-MM-DD`. Fluent: `before(Date|Closure)`, `after(Date|Closure)`, `between(Date|Closure, Date|Closure)`.
- [ ] `DateTime` — ISO-8601 with timezone. Fluent: same as `Date` with `DateTimeImmutable|Closure`, plus `timezone(string ...$allowed)`, `useTimezone(string)`, `retainTimezone()`.
- [ ] `Time` — `HH:MM:SS`. Fluent: `before(Time|Closure)`, `after(Time|Closure)`.
- [ ] All three accept `Closure` returning the bound at evaluation time; closures do not round-trip to JSON Schema. Document the trade-off.

### Composite fields

- [ ] `ArrayList` — zero-indexed array. Fluent: `minItems`, `maxItems`, `uniqueItems`, `each(Constraint ...)`. Also `sorted()` from Laravel (sort on serialization).
- [ ] `ArrayHash` — JSON object as associative array. Fluent: `minProperties`, `maxProperties`, `sortKeys()`, `sortValues()`. Key-case conversion helpers (`camelizeKeys`/`snakeKeys`/etc.) — keep minimal, defer richer support post-1.0 if it gets messy.
- [ ] `Map` — exposes a nested JSON object in the resource attributes while spreading the values across multiple flat columns on the same model. Child fields each carry their own constraints; constraints apply per-child-key in the nested object. Top-level `Map` constraints limited to `required` / `nullable`. `Map::on(string $relation)` (Laravel's related-model variant) is **out of scope for core** — requires ORM awareness; shipped in the Symfony bundle as a Doctrine-aware extension if there's demand. Document this clearly in `docs/fields.md`.

### Identifier field

- [ ] `Id` — the resource identifier. Fluent shortcuts: `uuid()`, `pattern(string)`, `numeric()`. Each appends the matching constraint.

### Relationship fields

- [ ] `Relation` interface; `AbstractRelation` base sharing `type(string|array)`, `inverseType()`, `retainFieldName()`, `withUriFieldName(string)`, `cannotEagerLoad()`, `readOnly`-family, `notSparseField`.
- [ ] `BelongsTo` — `belongsTo` / single-related-model. Constraints: `required`, `RelationshipType`.
- [ ] `HasOne` — `hasOne` / single-related-model. Constraints: same as `BelongsTo`.
- [ ] `HasMany` — collection of related models. Constraints: `required`, `RelationshipType`, `minItems`, `maxItems`.
- [ ] `BelongsToMany` — pivot-backed to-many. Constraints: same as `HasMany`, plus pivot-field declarations (`fields(Closure|array)`).
- [ ] `MorphTo` — polymorphic to-one with `types(string ...)` to declare allowed inverse types. Constraints: `required`, `RelationshipType`.

### Schema abstract base

- [ ] `haddowg\JsonApi\Schema\Schema` (or chosen name) — abstract base. Required:
  - `static string $type` — the JSON:API resource type
  - `fields(): array` — abstract; returns the field inventory
  - `filters(): array` — default empty
  - `sorts(): array` — default empty; sortable fields are derived from `fields()`
  - `pagination(): ?Paginator` — default null (no pagination)
- [ ] Implements `ResourceInterface` (yin's per-resource-type serializer contract from Phase 1): default implementations iterate `fields()` and call each field's serialization path.
- [ ] Implements the hydrator contract: default implementations iterate `fields()` and call each field's hydration path, respecting per-field read-only state and the current request context (POST/PATCH).
- [ ] `selfLink(bool)` toggle, `uriType(string)` override — match Laravel's conventions for resource URI shaping.

### Server

The Phase 1 `Server` placeholder is fleshed out into the full per-API-version configuration root in this phase. The Phase 1 rendering contract on response value objects (which already takes a `Server`-shaped argument) is unchanged; this phase populates the type with the schema/profile/middleware/URI/version state it carries.

- [ ] `haddowg\JsonApi\Server\Server` (or `haddowg\JsonApi\Server`) — the per-API-version value object. Holds:
  - Schema registry (resource type string → `Schema`), with optional `Resource` / `Hydrator` overrides per type
  - Profile registry (folded in from Phase 2)
  - Base URI for this API surface (e.g. `https://example.com/api/v1`)
  - JSON:API version (defaults to `1.1`)
  - Default top-level `jsonapi.meta` (defaults to empty)
  - Default `Paginator` (used when a schema doesn't override; can be `null`)
  - Encoding flag defaults for `json_encode` (`JSON_UNESCAPED_UNICODE`, etc.)
  - **Ordered middleware list** for this server's request lifecycle
- [ ] Implements `Psr\Http\Server\RequestHandlerInterface`. `Server::handle(ServerRequestInterface $request): ResponseInterface` runs the middleware chain composed with whatever inner handler the consumer wires (passed at construction or via a fluent setter — decide during implementation).
- [ ] Fluent construction:
  ```php
  $server = Server::make()
      ->withBaseUri('https://example.com/api/v1')
      ->withVersion('1.1')
      ->register(PostSchema::class)
      ->register(CommentSchema::class, resource: CustomCommentResource::class)
      ->withProfile(new MyCustomProfile())
      ->withDefaultPaginator(PagePaginator::make()->withDefaultPerPage(15))
      ->withMiddleware([
          new ErrorHandlerMiddleware($server, $psr17),
          new ContentNegotiationMiddleware($server),
          new RequestBodyParsingMiddleware($psr17),
      ])
      ->withHandler($operationHandler);  // an `OperationHandler` (Phase 1); wrapped in `Psr7ToOperationHandlerAdapter` automatically. A bare PSR-15 handler is also accepted as an escape hatch.
  ```
- [ ] Registration API on the schema registry (lives on `Server`):
  - `register(class-string<Schema> $schema, ?class-string<ResourceInterface> $resource = null, ?class-string<Hydrator> $hydrator = null): self` — returns a new server (immutable; matches readonly value-object semantics) with the schema registered.
- [ ] Lookup API: `schemas(): SchemaRegistry`, `profiles(): ProfileRegistry` accessors; or direct lookup methods (`$server->schemaFor(string $type)`, `$server->resourceFor(string $type)`, `$server->hydratorFor(string $type)`). Each returns the override if registered, otherwise the schema (which implements both contracts).
- [ ] Type → schema lookup is the primary mechanism the middleware (and response value objects' rendering paths) use to dispatch resource/hydrator resolution.
- [ ] **`Server::dispatch(JsonApiOperation $operation): DataResponse|MetaResponse|RelatedResponse|IdentifierResponse|ErrorResponse`.** Programmatic dispatch — invokes the configured `OperationHandler` directly without the PSR-15 chain. Bypasses content negotiation, body parsing, and the rest of the middleware (the operation is assumed to be pre-constructed and complete). Useful for integration tests, internal API calls, and the post-1.0 atomic-ops dispatcher's per-operation invocation. Returns the response value object directly; the caller is responsible for rendering if needed.
- [ ] **`Server::handle($request)`** (the PSR-15 entry point) runs the middleware chain composed with the inner handler. The inner handler is either the consumer's `OperationHandler` wrapped in `Psr7ToOperationHandlerAdapter`, or a PSR-15 handler the consumer supplied directly.
- [ ] **Multi-server / API-versioning support.** Servers are values, not singletons. A consumer or framework adapter constructs and holds multiple `Server` instances (e.g. `$v1`, `$v2`); routing logic outside core dispatches to the right one (`$path->startsWith('/api/v1') ? $v1 : $v2`). No core-level "server selector"; the routing layer is the right place for that.
- [ ] **Bootstrap-time validation.** When the server is finalised (e.g. on first `handle()` call), validate that every registered profile fragment is itself a valid JSON Schema (the Phase 4 hook decision), every registered schema has a unique `type`, no two profiles have the same URI. Fail fast at startup, not at request time.

### Filter contracts (metadata)

- [ ] `Filter` interface: small — `key(): string` and accessors for the filter's parameters (column, operator, etc.). **No `apply()` method.** Filters are value objects, not behaviour-carriers.
- [ ] Concrete filters as readonly value objects: `Where`, `WhereIn`, `WhereIdIn`, `WhereIdNotIn`, `WhereNotIn`, `WhereNull`, `WhereNotNull`, `WhereHas`, `WhereDoesntHave`. Each carries the fields the handler needs to know about (key, column, operator, delimiter, singular flag, deserialize closure, etc.). Each ships with a `make()` static factory for fluent construction.
- [ ] `singular()` and `deserializeUsing()` / `asBoolean()` fluent helpers return a new filter with the relevant fields adjusted (immutable; matches readonly-value-object semantics).
- [ ] Filters can declare their own constraints (for validating the incoming filter value). The constraint metadata feeds the same validation pipeline as field constraints.

### Sort contracts (metadata)

- [ ] `Sort` interface: small — `key(): string` and accessors for sort parameters (column, allowed directions). **No `apply()` method.**
- [ ] Concrete `SortByField` value object; built-in `SortByExpression` if needed for computed sorts (decide during implementation).
- [ ] Schemas auto-derive sort metadata from fields that declared `->sortable()`; explicit `sorts()` method on the schema is for sorts that don't map to a single field.

### Filter and sort handlers

- [ ] `FilterHandler` interface: `apply(Filter $filter, mixed $queryContext, mixed $value): void` (or returns the modified query — decide during implementation; argument is the adapter's native query builder, kept as `mixed` to avoid coupling core to any specific data layer).
- [ ] `SortHandler` interface: same shape with `Sort` and a direction parameter.
- [ ] Handlers are registered by adapters (Symfony bundle ships a `DoctrineFilterHandler` + `DoctrineSortHandler`). Core does not ship a Doctrine handler. The pattern matches the existing `Constraint` translator pattern.
- [ ] **Reference array-backed handlers** in `haddowg\JsonApi\Schema\Filter\InMemory\ArrayFilterHandler` and `haddowg\JsonApi\Schema\Sort\InMemory\ArraySortHandler`. Operate on PHP arrays. Used for the package's own integration tests and as a worked example for adapter authors. Document explicitly that these are **not** intended as a production query layer; they exist to demonstrate the pattern and avoid a database dependency in core tests.
- [ ] Handler-not-found behaviour: when a `Filter` (or `Sort`) reaches a handler that doesn't recognise it (e.g. a consumer's `Custom` filter without a registered handler), the handler throws a typed exception (`UnsupportedFilter`, `UnsupportedSort`) carrying the offending value object. The exception flows through the error middleware as a 500 (server config error, not a client error) with a useful diagnostic.

### Pagination integration

- [ ] Phase 2 paginators get fluent `make()` constructors and chainable builders (`withPageKey`, `withPerPageKey`, etc. — see Laravel for the vocabulary).
- [ ] `Schema::pagination()` returns a `?Paginator`. The associated profile (from Phase 2) is still emitted on the response.

### Validation constraint vocabulary

The full structural inventory; each is a readonly value object implementing `Constraint`.

- [ ] **Presence:** `Required`, `Nullable`
- [ ] **Lengths and bounds:** `MinLength`, `MaxLength`, `Min`, `Max`, `ExclusiveMin`, `ExclusiveMax`, `MultipleOf`, `MinItems`, `MaxItems`, `UniqueItems`, `MinProperties`, `MaxProperties`
- [ ] **Patterns:** `Pattern`, `In`, `NotIn`
- [ ] **String formats:** `EmailFormat`, `UrlFormat`, `UuidFormat`, `IpFormat`, `SlugFormat`
- [ ] **Date/time bounds:** `Before`, `After`, `Between`, `Timezone`
- [ ] **Composition:** `Each`, `When`, `Custom` (escape hatch)
- [ ] **Relationships:** `RelationshipType`

Each carries a `Context`. The `Custom` constraint is opaque — `Custom(string $id, mixed $payload)` — and is the documented mechanism for consumers / adapter packages to add constraints core doesn't model.

### JSON Schema compiler (extends Phase 4)

This is the Phase 4 amendment described in the prerequisites. Belongs in this phase, not Phase 4, because schemas don't exist until now.

- [ ] `haddowg\JsonApi\Validation\SchemaCompiler` — given a `Schema` and a request context (create/update), produces a JSON Schema document. Constraint → JSON Schema mapping table.
- [ ] Output is merged with the JSON:API 1.1 base schema and any profile-contributed fragments (Phase 4 hook).
- [ ] Compilation is cached at registry boot, keyed by `[type, context]`.
- [ ] `When` constraints are skipped during compilation (don't round-trip); `Custom` constraints are skipped during compilation (adapter-specific). Document both.
- [ ] The Phase 4 `RequestValidationMiddleware` now uses the per-resource compiled schema for the current request type, not just the base schema.

### Resource/hydrator escape-hatch documentation

- [ ] In-code: clarify in PHPDoc that `ResourceInterface` (yin's serializer) and the hydrator contract are first-class public API, recommended for cases where the schema's default behaviour is insufficient.
- [ ] Pattern entry in `CLAUDE.md`: when to override a resource (request-aware fields, conditional attributes, computed values, multiple representations of the same model); when to override a hydrator (split a field across columns, derive related models, multi-step writes).

### Testing utilities (public API)

A small `haddowg\JsonApi\Testing\*` namespace shipped as part of the package's autoload (not in `require-dev`-only territory; the assertions are useful in consumer test suites). Scope is deliberately small — assertion helpers around the document shape, error response shape, and request/operation construction. No factories, no fixture loaders, no database traits, no HTTP test clients. Anything beyond this is consumer or framework territory.

- [ ] `JsonApiDocument` — fluent document-assertion wrapper. Constructor accepts `Psr\Http\Message\ResponseInterface`, raw JSON `string`, parsed `array`, or any response value object (`DataResponse` etc.). Methods (return `$this` for chaining):
  - `assertHasType(string)` / `assertHasId(string)`
  - `assertHasAttribute(string $name, mixed $expected = null)` — equality check if `$expected` provided, presence check otherwise
  - `assertHasRelationship(string $name, ?string $expectedType = null, ?string $expectedId = null)`
  - `assertHasIncluded(string $type, ?int $count = null)` / `assertNotHasIncluded(string $type)`
  - `assertHasMetaKey(string)` / `assertMetaValue(string $key, mixed $expected)`
  - `assertHasLink(string $rel, ?string $expectedHref = null)`
  - `assertProfileApplied(string $uri)`
  - Lower-level escape hatches: `data()`, `included()`, `meta()`, `links()` accessors returning the raw parsed structure for ad-hoc assertions
- [ ] `JsonApiErrors` — fluent error-assertion wrapper. Same input shapes as `JsonApiDocument`. Methods:
  - `assertCount(int)` / `assertHasError(?string $status = null, ?string $pointer = null, ?string $code = null)`
  - `assertHasErrorAt(string $pointer)` — checks at least one error has `source.pointer` matching
  - `assertHasErrorWithCode(string)`
  - `errors(): array` accessor for ad-hoc assertions
- [ ] `JsonApiRequestBuilder` — builds PSR-7 `ServerRequestInterface` for integration tests. Fluent: `JsonApiRequestBuilder::post('/api/posts')->withResource('posts', attributes: [...])->withProfile(...)->withAccept('application/vnd.api+json')->build()`. Equivalents for `get`, `patch`, `delete`. Returns a `ServerRequestInterface` with correct `Content-Type`, `Accept`, body, and query parameters.
- [ ] `JsonApiOperationBuilder` — builds `JsonApiOperation` instances directly for programmatic-dispatch tests. Fluent: `JsonApiOperationBuilder::create('posts')->withAttribute('title', 'Hello')->withRelationship('author', type: 'users', id: '42')->build()` returns a `CreateResourceOperation`. Equivalents for the other verbs. Pairs with `Server::dispatch($operation)` for integration tests without the PSR-15 chain.
- [ ] `assertJsonApiSpecCompliant(mixed $document): void` — wraps Phase 4's `DocumentValidator` as a one-line assertion. Validates the document against the JSON:API 1.1 base schema + any profile fragments. Throws a PHPUnit-compatible failure on violation. Available as both a static helper and a PHPUnit-style assertion trait, decide during implementation.
- [ ] **Out of scope** (record explicitly in the decision log to head off scope creep):
  - PHPUnit `Constraint` wrappers (duplicate the chainable helpers; skip unless someone asks)
  - Factories / fixture loaders (application concern)
  - Database test traits (adapter territory)
  - HTTP test clients (PHPUnit, Pest, Codeception ecosystems)
- [ ] Tests for the testing utilities themselves. The assertions need to pass on well-formed inputs and fail with clear messages on malformed inputs.

### Spec compliance update

- [ ] Update `docs/spec-compliance.md` for the additional MUSTs / SHOULDs the schema-driven validation now asserts (sparse fieldsets per type, sort allowed-fields enforcement, filter parameter shape).

### Tests

- [ ] Field-type unit tests file-by-file alongside implementations (per `CLAUDE.md` operational rules). Each field type's tests cover: construction, every fluent method, constraint metadata produced, serialization path, hydration path, sparse-fieldset participation, read-only enforcement under POST and PATCH contexts.
- [ ] Constraint unit tests: each constraint constructed under each context, metadata round-trips.
- [ ] Schema integration tests: a sample `PostSchema` with several fields, verify it satisfies the resource + hydrator contracts; verify overriding the resource takes precedence; verify overriding the hydrator takes precedence.
- [ ] Filter/sort tests against the reference `ArrayFilterHandler` / `ArraySortHandler`. Cover every built-in filter and sort end-to-end against a sample in-memory dataset.
- [ ] JSON Schema compiler tests: per-resource compiled schema validates well-formed bodies, rejects malformed bodies, produces correct `source.pointer` values.

## Decision log

_(Appended to during execution.)_

| Date | Decision | Rationale | Affects |
|---|---|---|---|
| _yyyy-mm-dd_ | _(example: renamed `haddowg\JsonApi\Schema\*` to `haddowg\JsonApi\Document\*` to free the `Schema` name for the fluent type)_ | _(rationale)_ | _(this phase / future phases)_ |

## Open questions

- `Server` shape: single value object with internal sub-structures, or compose of `SchemaRegistry` + `ProfileRegistry` + `Server` wrapper? Lean: single value, sub-structures accessible via accessors. Confirm at kick-off (resolved here, in the kick-off step).
- `FilterHandler::apply()` and `SortHandler::apply()` signature: take an adapter-native query as `mixed`, or as a generic `object`, or as a templated parameter (PHPStan generics)? `mixed` is simplest; generics give better tooling but PHPStan-only. Lean: `mixed` with `@template` hints in PHPDoc; adapters narrow in their own implementations.
- Should `Schema::filters()` and `Schema::sorts()` be `array` or `iterable`? Laravel uses `iterable`. Doesn't affect semantics; consistency with the rest of the codebase decides. Lean: `iterable` matches modern PHP idioms.
- Pivot-field metadata on `BelongsToMany` — fully shipped in 1.0, or trimmed (declare-only, no pivot-field validation)? Lean: declare-only in 1.0; full pivot validation is a refinement.
- Constraint context default: all constraints default to `Context::always()`; the per-field `onCreate()`/`onUpdate()` builders are the way to scope. Confirm vs. an alternative where each constraint's constructor takes the context explicitly. Lean: default-always + per-field builder is the cleanest DX.
- For computed/derived attributes (no underlying column), what's the fluent shape? Likely `Str::make('preview')->extractUsing(Closure)` — the column argument is null. Confirm.

## Acceptance criteria

The phase is done when all of the following hold:

1. All task-list items are checked off.
2. The namespace clash is resolved; CI green after the rename (if rename was the chosen path).
3. A sample `PostSchema` (in `tests/`) declares fields, filters, sorts, pagination, and constraints, and satisfies the resource + hydrator contracts end-to-end.
4. A second sample resource type registers a custom resource (serializer) that overrides the schema's default serialization; the override is exercised in tests.
5. A third sample resource type registers a custom hydrator; ditto.
6. The full constraint vocabulary in the task list is implemented; each constraint has a unit test asserting its metadata shape and context behaviour.
7. The JSON Schema compiler produces correct JSON Schema documents from sample schemas in both create and update contexts; integration tests prove the Phase 4 `RequestValidationMiddleware` uses the per-resource compiled schema.
8. Phase 1's `Resource` + `Hydrator` public API is unchanged; consumers can still write a standalone resource + hydrator pair without the schema layer.
9. `docs/spec-compliance.md` updated for the new MUSTs/SHOULDs the schema layer asserts (sparse fieldsets, sort allowed-fields, filter parameter shape).
10. PHPStan level 9 passes; CI matrix green; spec-tagged tests pass.
11. `CLAUDE.md` updated with pattern entries for each new component kind: `Field`, `Constraint`, `Filter`, `Sort`, `Schema`, `Server`. Plus a "when to use a custom resource/hydrator" pattern.
12. **Multi-server / versioning support is observable end-to-end.** A test constructs two `Server` instances (`$v1`, `$v2`) with different schemas and middleware lists, dispatches requests to each, and confirms each runs its own chain with its own configuration. The Phase 3 multi-server integration test (which used the Phase 1 placeholder) now runs against the full `Server`.
13. `Server` implements `Psr\Http\Server\RequestHandlerInterface`; a test asserts that `$server->handle($request)` produces the expected PSR-7 response for a sample request, going through the full middleware chain.
14. Testing utilities under `haddowg\JsonApi\Testing\*` ship — `JsonApiDocument`, `JsonApiErrors`, `JsonApiRequestBuilder`, `JsonApiOperationBuilder`, `assertJsonApiSpecCompliant`. Each has its own unit tests proving the assertions pass on well-formed inputs and fail with informative messages on malformed inputs.

### Verification plan

```bash
composer install
composer test
composer phpstan
composer cs-check

# Validation + schema spec coverage
vendor/bin/phpunit --group spec:document-structure
vendor/bin/phpunit --group spec:fetching-resources
vendor/bin/phpunit --group spec:fetching-relationships
vendor/bin/phpunit --group spec:inclusion-of-related-resources
vendor/bin/phpunit --group spec:sparse-fieldsets
vendor/bin/phpunit --group spec:sorting
vendor/bin/phpunit --group spec:filtering
vendor/bin/phpunit --group spec:pagination
vendor/bin/phpunit --group spec:crud

# Lowest-deps run
composer update --prefer-lowest --prefer-stable
composer test
```

Manual review:

- Write a throwaway schema in a sandbox script with one field of each type and one constraint of each kind; confirm the registry resolves it correctly, the resource's serialization output is well-formed, and the hydrator round-trips a sample request to a sample model.
- Register a custom resource (serializer) for that schema; confirm the override takes precedence.
- Register a custom hydrator; ditto.
- Confirm a non-schema consumer (registering a resource + hydrator directly per Phase 1) still works exactly as in Phase 1.
- Confirm the JSON Schema compiler produces sensible JSON Schema for a schema with several constraints; manually run a malformed POST body through `RequestValidationMiddleware` and confirm the error document carries correct `source.pointer` values.

## Handover output

Before declaring the phase complete, produce the following for Phase 5:

1. **Status table update** in `docs/PLAN.md` — Phase 4.5 → `Complete`, Phase 5 → `Ready`.
2. **Phase 5 plan review** — `docs/phase-5-docs.md` already exists as a pre-drafted plan, amended at the end of this phase. Read it end-to-end against this phase's decision log and current repository state. Confirm it still covers at minimum:
   - Docs **lead with the schema** as the recommended public surface; `Resource` (yin's serializer) and `Hydrator` are documented as escape hatches with worked examples (request-aware fields, conditional attributes, computed values; multi-step hydration, split-column writes).
   - New page `docs/validation.md` covers the constraint vocabulary, the create/update context model, the `Required` semantics convention, and the `Custom` escape hatch.
   - New page `docs/schemas.md` covers field types, fluent builders, the field-type/constraint compatibility matrix, and the `Map`/`on()` decision (likely "see the Symfony bundle docs").
   - Quick-start uses a schema-based example, not a resource/hydrator pair.
   - Append revisions to the plan as a single commit; the actual kick-off revision happens at the start of Phase 5, but corrections forced by Phase 4.5 decisions belong here.
3. **Open questions resolved** — every entry in the Open questions section above has an answer recorded in the decision log. Resolve any remaining or newly-surfaced questions by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides. Open questions are not passed forward to Phase 5.
4. **Decision log finalised** — phase-local decisions captured here; any cross-phase decisions promoted to `PLAN.md`.

### Note on deferred work

- **Attribute-driven schemas** (`#[ResourceType]`, `#[Attribute]`, `#[Relationship]`) are a clean future enhancement on top of this layer. Post-1.0 candidate.
- **Doctrine `FilterHandler` and `SortHandler`, Symfony bundle integration, generators, eager-load planning, authorization, validation execution** all live in the Symfony bundle. The contracts established here are the integration surface.
- **OpenAPI spec generation** will consume the same `Field` and `Constraint` metadata that the JSON Schema compiler does, plus the per-verb operation classes for path enumeration. No additional metadata or hooks are needed in this phase; the post-1.0 generator can be designed against the 1.0 schema layer as-is.
- **`HtmlString` / `RichText` field**, **richer `ArrayHash` key-case helpers**, **full pivot-field validation**, **`Map::on()` semantics** — deferred to post-1.0 minors, scheduled when concrete consumers appear.

## Phase 4 reconciliation notes (appended at Phase 4 close)

These corrections are forced by Phase 4 decisions; fold them into the plan at the
Phase 4.5 kick-off revision (see `docs/phase-4-validation.md` decision log).

- **The validator entry point for per-resource schemas already exists and is a
  list, not a third positional argument.** `Validation\DocumentValidator` exposes
  `validateRequest(mixed $document, list<object> $additionalSchemas = [])` and
  `validateResponse(...)` with the same shape. Both the profile fragments (Phase 4)
  and this phase's **compiled per-resource schema** are passed as entries of
  `$additionalSchemas`; the validator composes them with the base/request root via
  `allOf`. **No new validator method or signature change is needed** — the
  `SchemaCompiler` simply produces a decoded JSON Schema object (`stdClass`) and the
  request-validation path adds it to that list. (The plan's "third input" is
  realised as "one more entry in the composition list.")
- **`SchemaCompiler` output shape.** Produce a decoded JSON Schema (draft 2020-12)
  `stdClass` — the same form `SchemaContributingProfile::schemaFragment()` returns —
  so it drops straight into `$additionalSchemas`. A per-resource schema *tightens*
  (`allOf`), typically via `{"properties":{"data":{"properties":{"attributes":{…},
  "relationships":{…}}}}}`; it does not need to relax `unevaluatedProperties` (only
  top-level profile members do, and that relocation is already handled by
  `VendoredSchemaProvider`/the composite). Compile create vs update contexts to two
  schemas (the `Required`/`requiredOnCreate`/`requiredOnUpdate` split maps to
  `required` arrays per context).
- **Wiring the per-resource schema into `RequestValidationMiddleware`.** The
  Phase 4 middleware is `RequestValidationMiddleware(ServerInterface $server,
  DocumentValidator $validator)` and currently gathers only profile fragments (via
  `@internal Validation\Internal\ProfileSchemaCollector`). This phase extends the
  request path to also look up the compiled schema for the request's resource type
  from the `Server`'s schema registry (the `Server` is already injected) and append
  it to the `$additionalSchemas` passed to `validateRequest()`. Decide whether the
  lookup lives in the middleware or a small collaborator beside
  `ProfileSchemaCollector`; either way the validator API is unchanged.
- **Exceptions are reused, not new.** Validation failures throw the existing
  `Exception\RequestBodyInvalidJsonApi` (400) and `Exception\ResponseBodyInvalidJsonApi`
  (500) — there is **no** `DocumentValidationFailed` class. The compiler/middleware
  tests (task "JSON Schema compiler tests … correct `source.pointer`") should assert
  against those types and their `validationErrors` (`list<array{message, property?}>`,
  where `property` is the JSON pointer that becomes `Error::$source->pointer`).
- **`assertJsonApiSpecCompliant(mixed $document)`** (testing-utilities task) wraps
  `DocumentValidator::validateResponse($document)` and converts a thrown
  `ResponseBodyInvalidJsonApi` into a PHPUnit failure (surfacing each violation's
  pointer + message). It needs a `SchemaProvider` — default it to
  `new VendoredSchemaProvider()`.
- **`Server` placeholder → full `Server` keeps the render contract.** The full
  `Server` must continue to implement `Server\ServerInterface` exactly as today
  (`baseUri()`, `jsonApiVersion()`, `defaultMeta()`, `encodeOptions()`,
  `profiles()`, `responseFactory()`, `streamFactory()`) so the response value
  objects' `toPsrResponse()` and the validation middleware (which type-hint
  `ServerInterface`) drop in unchanged. Add the schema/override registry as new
  surface on top; do not alter the existing methods' signatures.
- **`opis/json-schema` stays optional.** It is `require-dev` + `suggest` only. The
  `SchemaCompiler` is core (it emits JSON Schema *data*, no opis dependency), but
  anything that *runs* a validator (the middleware, `assertJsonApiSpecCompliant`)
  requires opis at the call site — keep that boundary so production installs that
  don't validate still don't pull opis.
