<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Server;

use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Operation\OperationHandler;
use haddowg\JsonApi\Operation\Psr7ToOperationHandlerAdapter;
use haddowg\JsonApi\Pagination\Paginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Profile\ProfileInterface;
use haddowg\JsonApi\Schema\Profile\ProfileRegistry;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\Internal\MiddlewareDecorator;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The per-API-version configuration root: the Resource registry (+ overrides), the
 * profile registry, base URI, JSON:API version, default `jsonapi.meta`, default
 * paginator, `json_encode` flags, the PSR-17 factories, and the ordered
 * middleware list for one API surface.
 *
 * It is a value (built fluently, immutable: each `with…()`/`register()` returns
 * a new instance) and is itself a PSR-15 {@see RequestHandlerInterface}:
 * {@see handle()} runs the middleware chain composed with the inner handler.
 * {@see dispatch()} invokes the configured {@see OperationHandler} directly,
 * bypassing the chain (for integration tests and programmatic calls).
 *
 * Multiple servers = multiple API versions; routing outside core dispatches to
 * the right one. It implements the {@see ServerInterface} render contract, so
 * the response value objects drop in as-is.
 */
final class Server implements ResolvingServerInterface, RequestHandlerInterface
{
    private ResourceRegistry $resources;

    private ProfileRegistry $profiles;

    private string $baseUri = '';

    private string $jsonApiVersion = JsonApiObject::VERSION;

    /**
     * @var array<string, mixed>
     */
    private array $defaultMeta = [];

    private int $encodeOptions = 0;

    private ?\haddowg\JsonApi\Pagination\PaginatorInterface $defaultPaginator = null;

    private ?int $maxIncludeDepth = null;

    /**
     * Whether to reject an unrecognized query-parameter family with a `400`
     * up front (before the operation handler runs). Default on: a client typo —
     * `?foo`, a misspelled `?pag[number]`, a wrong-cased custom param — that the
     * server does not recognize surfaces as a clean error instead of being
     * silently dropped (a wrong-but-`200` result). Relax it
     * ({@see withStrictQueryParameters()} false) to restore the
     * tolerant-by-default behaviour (silent ignore).
     */
    private bool $strictQueryParameters = true;

    /**
     * The implementation-specific query-parameter family base names this server
     * recognizes in addition to the reserved JSON:API families and `withCount`,
     * registered by the host ({@see withCustomQueryParameter()}). A negotiated
     * profile's keywords are added per request from the profile registry, not here.
     *
     * @var list<string>
     */
    private array $customQueryParameters = [];

    private ?ResponseFactoryInterface $responseFactory = null;

    private ?StreamFactoryInterface $streamFactory = null;

    /**
     * @var list<MiddlewareInterface>
     */
    private array $middleware = [];

    /**
     * The registered server-level `serving` handlers, fired once per dispatch
     * (= once per request) at the start of {@see dispatch()}, before the
     * operation handler runs. Each may throw a {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}
     * to abort; the throw propagates out of {@see dispatch()} unchanged.
     *
     * @var list<\Closure(\haddowg\JsonApi\Request\JsonApiRequestInterface): void>
     */
    private array $serving = [];

    private \haddowg\JsonApi\Operation\OperationHandlerInterface|RequestHandlerInterface|null $handler = null;

    /**
     * The storage-aware relationship load-state predicate, or null for the
     * standalone default (every relation treated as loaded; linkage data always
     * emitted). Pushed into the {@see ResourceRegistry} — the resolver relations
     * actually consult — the same way the lazy instantiation factory is.
     */
    private ?\haddowg\JsonApi\Serializer\RelationshipLoadStateInterface $relationshipLoadState = null;

    /**
     * The storage-aware resolver that supplies a countable relation's cardinality
     * (the `meta.total` rendered on a `?withCount`-named relationship), or null for
     * the standalone default (no count available, so no `meta.total`). Pushed into
     * the {@see ResourceRegistry} the same way the load-state predicate is.
     */
    private ?\haddowg\JsonApi\Serializer\RelationshipCountInterface $relationshipCount = null;

    /**
     * The storage-aware resolver that supplies a to-many relation's page-1
     * pagination state (the relationship-object pagination links) under the
     * Relationship Queries profile, or null for the standalone default (no such
     * links emitted). Pushed into the {@see ResourceRegistry} the same way the
     * count resolver is.
     */
    private ?\haddowg\JsonApi\Serializer\RelationshipPaginationInterface $relationshipPagination = null;

    /**
     * The lazy instantiation factory threaded into the {@see ResourceRegistry}, or
     * null to fall back to plain `new`. Server is the single source of truth and
     * pushes it into the cloned registry on every clone.
     *
     * @var (\Closure(class-string): object)|null
     */
    private ?\Closure $resolver = null;

    public function __construct()
    {
        $this->resources = new ResourceRegistry();
        $this->profiles = new ProfileRegistry();
    }

    public static function make(): self
    {
        return new self();
    }

    public function withBaseUri(string $baseUri): self
    {
        $self = clone $this;
        $self->baseUri = $baseUri;

        return $self;
    }

    public function withVersion(string $jsonApiVersion): self
    {
        $self = clone $this;
        $self->jsonApiVersion = $jsonApiVersion;

        return $self;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function withDefaultMeta(array $meta): self
    {
        $self = clone $this;
        $self->defaultMeta = $meta;

        return $self;
    }

    public function withEncodeOptions(int $encodeOptions): self
    {
        $self = clone $this;
        $self->encodeOptions = $encodeOptions;

        return $self;
    }

    public function withDefaultPaginator(?\haddowg\JsonApi\Pagination\PaginatorInterface $paginator): self
    {
        $self = clone $this;
        $self->defaultPaginator = $paginator;

        return $self;
    }

    /**
     * Sets the default maximum include depth (number of relationship hops from the
     * primary resource) for every resource this server renders. Core is
     * unopinionated: `null` (the default) means unlimited, as does any value
     * `<= 0`. A resource may override it via
     * {@see \haddowg\JsonApi\Serializer\IncludeControlsInterface::maxIncludeDepth()}.
     */
    public function withMaxIncludeDepth(?int $depth): self
    {
        $self = clone $this;
        $self->maxIncludeDepth = $depth;

        return $self;
    }

    /**
     * Toggles strict query-parameter validation (default `true`). When on, a
     * query parameter whose **family base name** the server does not recognize is
     * rejected with a `400` {@see \haddowg\JsonApi\Exception\QueryParamUnrecognized}
     * up front, before the operation handler runs — so a client typo surfaces as a
     * clean error rather than a silently-dropped, wrong-but-`200` result. Passing
     * `false` restores the tolerant behaviour: an unrecognized family is ignored.
     *
     * The recognized set is the reserved JSON:API families, `withCount`, every
     * {@see withCustomQueryParameter()} the host registered, and the reserved
     * keywords of every registered profile the request negotiated.
     */
    public function withStrictQueryParameters(bool $strict = true): self
    {
        $self = clone $this;
        $self->strictQueryParameters = $strict;

        return $self;
    }

    /**
     * Registers one or more implementation-specific query-parameter family base
     * names this server recognizes (e.g. a host's own `withTrashed`), so strict
     * validation does not reject them. Each name should carry a non-`a-z`
     * character to satisfy the spec's custom-parameter naming rule, though the
     * recognition itself is name-exact and does not enforce that.
     *
     * Appends to the existing set; `withCount` and the reserved JSON:API families
     * are always recognized and need not be registered. A negotiated profile's
     * keywords are recognized automatically and likewise need no registration.
     */
    public function withCustomQueryParameter(string ...$names): self
    {
        $self = clone $this;
        $self->customQueryParameters = [...$this->customQueryParameters, ...\array_values($names)];

        return $self;
    }

    public function withPsr17(ResponseFactoryInterface $responseFactory, StreamFactoryInterface $streamFactory): self
    {
        $self = clone $this;
        $self->responseFactory = $responseFactory;
        $self->streamFactory = $streamFactory;

        return $self;
    }

    /**
     * Registers a server-level `serving` handler, fired once per dispatch
     * (= once per request) at the start of {@see dispatch()}, before the
     * operation runs — the request-scoped seam for cross-cutting concerns
     * (authorization gates, request-wide setup) that every operation shares.
     *
     * Handlers are appended: each call adds one more, and on dispatch they fire
     * in registration order. A handler may throw a
     * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} to abort the
     * request — the throw propagates out of {@see dispatch()} unchanged (callers
     * already map JSON:API exceptions to error responses), so the operation
     * handler never runs.
     *
     * @param \Closure(\haddowg\JsonApi\Request\JsonApiRequestInterface): void $handler
     */
    public function withServing(\Closure $handler): self
    {
        $self = clone $this;
        $self->serving[] = $handler;

        return $self;
    }

    /**
     * Registers a Resource class for its declared type, with optional
     * serializer / hydrator overrides.
     *
     * @param class-string<AbstractResource>         $resource
     * @param class-string<SerializerInterface>|null $serializer
     * @param class-string<HydratorInterface>|null   $hydrator
     */
    public function register(string $resource, ?string $serializer = null, ?string $hydrator = null): self
    {
        $self = clone $this;
        $self->resources = clone $this->resources;
        $self->resources->setResolver($self->resolver);
        $self->resources->setRelationshipLoadState($self->relationshipLoadState);
        $self->resources->setRelationshipCount($self->relationshipCount);
        $self->resources->setRelationshipPagination($self->relationshipPagination);
        $self->resources->register($resource, $serializer, $hydrator);

        return $self;
    }

    /**
     * Registers a bare serializer + hydrator pair under an explicit `$type`, with
     * no Resource class. At least one of the two must be supplied; the missing
     * concern has no Resource fallback. Use this when a type has no field-driven
     * Resource declaration.
     *
     * @param class-string<SerializerInterface>|null $serializer
     * @param class-string<HydratorInterface>|null   $hydrator
     */
    public function registerSerializerHydrator(string $type, ?string $serializer = null, ?string $hydrator = null): self
    {
        $self = clone $this;
        $self->resources = clone $this->resources;
        $self->resources->setResolver($self->resolver);
        $self->resources->setRelationshipLoadState($self->relationshipLoadState);
        $self->resources->setRelationshipCount($self->relationshipCount);
        $self->resources->setRelationshipPagination($self->relationshipPagination);
        $self->resources->registerSerializerHydrator($type, $serializer, $hydrator);

        return $self;
    }

    /**
     * Sets the lazy instantiation factory the registry uses to build registered
     * Resources, serializers and hydrators. Accepts a PSR-11 container or any
     * `callable(class-string): object`; both are normalised to a `\Closure`. With
     * no resolver set the registry falls back to plain `new`.
     *
     * Lookups are lazy, so calling this before or after `register()` is
     * equivalent — the resolver lives on the registry and is consulted on first
     * lookup of each type.
     *
     * @param \Psr\Container\ContainerInterface|callable(class-string): object $resolver
     */
    public function withContainer(\Psr\Container\ContainerInterface|callable $resolver): self
    {
        $self = clone $this;
        $self->resolver = self::normalise($resolver);
        $self->resources = clone $this->resources;
        $self->resources->setResolver($self->resolver);
        $self->resources->setRelationshipLoadState($self->relationshipLoadState);
        $self->resources->setRelationshipCount($self->relationshipCount);
        $self->resources->setRelationshipPagination($self->relationshipPagination);

        return $self;
    }

    /**
     * Injects the storage-aware predicate relations consult — when they are lazy
     * ({@see \haddowg\JsonApi\Resource\Field\RelationInterface::emitsDataOnlyWhenLoaded()})
     * — to decide whether their linkage is already loaded and so cheap to emit.
     * Passing `null` (the default) restores the standalone behaviour: every
     * relation is treated as loaded and its linkage data is emitted as today.
     */
    public function withRelationshipLoadState(?\haddowg\JsonApi\Serializer\RelationshipLoadStateInterface $relationshipLoadState): self
    {
        $self = clone $this;
        $self->relationshipLoadState = $relationshipLoadState;
        $self->resources = clone $this->resources;
        $self->resources->setResolver($self->resolver);
        $self->resources->setRelationshipLoadState($self->relationshipLoadState);
        $self->resources->setRelationshipCount($self->relationshipCount);
        $self->resources->setRelationshipPagination($self->relationshipPagination);

        return $self;
    }

    /**
     * Injects the storage-aware resolver a countable relation consults — when it
     * is {@see \haddowg\JsonApi\Resource\Field\RelationInterface::isCountable()}
     * and named in the request's `?withCount` — for the `meta.total` rendered on
     * its relationship object. Passing `null` (the default) restores the standalone
     * behaviour: no count is available, so no `meta.total` is emitted even for a
     * countable, `?withCount`-named relation. The data-layer adapter batches the
     * counts across the fetched page (avoiding an N+1) and supplies them here.
     */
    public function withRelationshipCount(?\haddowg\JsonApi\Serializer\RelationshipCountInterface $relationshipCount): self
    {
        $self = clone $this;
        $self->relationshipCount = $relationshipCount;
        $self->resources = clone $this->resources;
        $self->resources->setResolver($self->resolver);
        $self->resources->setRelationshipLoadState($self->relationshipLoadState);
        $self->resources->setRelationshipCount($self->relationshipCount);
        $self->resources->setRelationshipPagination($self->relationshipPagination);

        return $self;
    }

    /**
     * Injects the storage-aware resolver a to-many relation consults — when the
     * Relationship Queries profile is negotiated — for the page-1 pagination state
     * rendered as the relationship-object `first` / `prev` / `next` (+ `last`)
     * links. Passing `null` (the default) restores the standalone behaviour: no
     * relationship-object pagination links are emitted. The data-layer adapter
     * windows each relation to page 1 (ordered/filtered by the per-relationship
     * sort/filter) and supplies the page here.
     */
    public function withRelationshipPagination(?\haddowg\JsonApi\Serializer\RelationshipPaginationInterface $relationshipPagination): self
    {
        $self = clone $this;
        $self->relationshipPagination = $relationshipPagination;
        $self->resources = clone $this->resources;
        $self->resources->setResolver($self->resolver);
        $self->resources->setRelationshipLoadState($self->relationshipLoadState);
        $self->resources->setRelationshipCount($self->relationshipCount);
        $self->resources->setRelationshipPagination($self->relationshipPagination);

        return $self;
    }

    public function withProfile(ProfileInterface $profile): self
    {
        $self = clone $this;
        $self->profiles = clone $this->profiles;
        $self->profiles->register($profile);

        return $self;
    }

    /**
     * Replaces the ordered middleware list.
     *
     * @param list<MiddlewareInterface> $middleware
     */
    public function withMiddleware(array $middleware): self
    {
        $self = clone $this;
        $self->middleware = \array_values($middleware);

        return $self;
    }

    /**
     * Sets the inner handler the middleware chain wraps. An {@see OperationHandler}
     * is wrapped in {@see Psr7ToOperationHandlerAdapter} automatically; a bare
     * PSR-15 handler is also accepted directly.
     */
    public function withHandler(\haddowg\JsonApi\Operation\OperationHandlerInterface|RequestHandlerInterface $handler): self
    {
        $self = clone $this;
        $self->handler = $handler;

        return $self;
    }

    // --- ServerInterface -----------------------------------------------------

    public function baseUri(): string
    {
        return $this->baseUri;
    }

    public function jsonApiVersion(): string
    {
        return $this->jsonApiVersion;
    }

    public function defaultMeta(): array
    {
        return $this->defaultMeta;
    }

    public function encodeOptions(): int
    {
        return $this->encodeOptions;
    }

    public function profiles(): ProfileRegistry
    {
        return $this->profiles;
    }

    public function responseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory
            ?? throw new \LogicException('No PSR-17 response factory configured; call withPsr17().');
    }

    public function streamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory
            ?? throw new \LogicException('No PSR-17 stream factory configured; call withPsr17().');
    }

    // --- Resource registry accessors ------------------------------------------

    /**
     * @internal the registry is package-internal; consumers reach a type's
     *           Resource via {@see resourceFor()}, and register through the fluent
     *           {@see register()} / {@see registerSerializerHydrator()}
     */
    public function resources(): ResourceRegistry
    {
        return $this->resources;
    }

    /**
     * The {@see AbstractResource} registered for `$type`.
     *
     * @throws \haddowg\JsonApi\Exception\NoResourceRegistered when `$type` has no Resource class
     */
    public function resourceFor(string $type): AbstractResource
    {
        return $this->resources->resourceFor($type);
    }

    /**
     * Whether `$type` has a Resource class (vs a bare serializer/hydrator pair) —
     * the presence-check mirror of {@see resourceFor()}.
     */
    public function hasResourceFor(string $type): bool
    {
        return $this->resources->hasResourceFor($type);
    }

    public function defaultPaginator(): ?\haddowg\JsonApi\Pagination\PaginatorInterface
    {
        return $this->defaultPaginator;
    }

    public function maxIncludeDepth(): ?int
    {
        return $this->maxIncludeDepth;
    }

    /**
     * Whether strict query-parameter validation is on (default `true`).
     */
    public function strictQueryParameters(): bool
    {
        return $this->strictQueryParameters;
    }

    /**
     * The host-registered custom query-parameter family base names, in
     * registration order — exposed primarily for inspection and tests.
     *
     * @return list<string>
     */
    public function customQueryParameters(): array
    {
        return $this->customQueryParameters;
    }

    /**
     * The registered server-level `serving` handlers, in registration order —
     * exposed primarily for inspection and tests.
     *
     * @return list<\Closure(\haddowg\JsonApi\Request\JsonApiRequestInterface): void>
     */
    public function serving(): array
    {
        return $this->serving;
    }

    public function serializerFor(string $type): SerializerInterface
    {
        return $this->resources->serializerFor($type);
    }

    public function hasSerializerFor(string $type): bool
    {
        return $this->resources->hasSerializerFor($type);
    }

    public function relationshipLoadState(): ?\haddowg\JsonApi\Serializer\RelationshipLoadStateInterface
    {
        return $this->relationshipLoadState;
    }

    public function relationshipCount(): ?\haddowg\JsonApi\Serializer\RelationshipCountInterface
    {
        return $this->relationshipCount;
    }

    public function relationshipPagination(): ?\haddowg\JsonApi\Serializer\RelationshipPaginationInterface
    {
        return $this->relationshipPagination;
    }

    public function hydratorFor(string $type): HydratorInterface
    {
        return $this->resources->hydratorFor($type);
    }

    public function hasHydratorFor(string $type): bool
    {
        return $this->resources->hasHydratorFor($type);
    }

    // --- PSR-15 entry point + programmatic dispatch -------------------------

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handler = $this->innerHandler();

        foreach (\array_reverse($this->middleware) as $middleware) {
            $handler = new MiddlewareDecorator($middleware, $handler);
        }

        return $handler->handle($request);
    }

    /**
     * Invokes the configured {@see OperationHandler} directly, without the PSR-15
     * chain. The operation is assumed pre-constructed and complete.
     */
    public function dispatch(
        \haddowg\JsonApi\Operation\JsonApiOperationInterface $operation,
    ): DataResponse|MetaResponse|RelatedResponse|IdentifierResponse|NoContentResponse|ErrorResponse {
        $handler = $this->handler;
        if (!$handler instanceof \haddowg\JsonApi\Operation\OperationHandlerInterface) {
            throw new \LogicException('Server::dispatch() requires an OperationHandler; call withHandler().');
        }

        $this->validateStrictQueryParameters($operation);
        $this->fireServing($operation);

        return $handler->handle($operation);
    }

    /**
     * Runs strict query-parameter validation for the dispatched operation, when
     * enabled and the operation is backed by an HTTP request. A programmatic
     * dispatch with no HTTP message has no query string to validate and is skipped.
     *
     * @throws \haddowg\JsonApi\Exception\QueryParamUnrecognized
     */
    private function validateStrictQueryParameters(
        \haddowg\JsonApi\Operation\JsonApiOperationInterface $operation,
    ): void {
        $request = $operation->context()->httpRequest();
        if ($request instanceof \haddowg\JsonApi\Request\JsonApiRequestInterface) {
            $this->validateStrictQueryParametersOf($request);
        }
    }

    /**
     * Validates a JSON:API request's query parameters against this server's
     * recognized set, when strict mode is on. The recognized set is assembled per
     * the resolved primary resource's vocabulary: the reserved JSON:API families,
     * the host-registered custom params, and the reserved keywords of every
     * registered profile this request negotiated (including the Countable
     * profile's `withCount`). An unrecognized family
     * base name throws {@see \haddowg\JsonApi\Exception\QueryParamUnrecognized}
     * (`400`). Shared by the programmatic {@see dispatch()} path and the PSR-15
     * {@see handle()} path (via the adapter hook).
     *
     * @throws \haddowg\JsonApi\Exception\QueryParamUnrecognized
     * @throws \haddowg\JsonApi\Exception\FieldsetMemberUnrecognized
     */
    private function validateStrictQueryParametersOf(
        \haddowg\JsonApi\Request\JsonApiRequestInterface $request,
    ): void {
        if ($this->strictQueryParameters === false) {
            return;
        }

        $validator = new \haddowg\JsonApi\Negotiation\StrictQueryParameterValidator(
            $this->recognizedCustomQueryParameters($request),
        );
        $validator->validate($request);

        $this->validateStrictFieldsetMembers($request);
    }

    /**
     * Rejects an unrecognized `fields[type]` sparse-fieldset MEMBER under the same
     * strict gate as the family validation above. For each type named in the
     * request's `fields[...]` map that the registry can resolve to a serializer
     * declaring its field namespace ({@see \haddowg\JsonApi\Serializer\DeclaresFieldNamesInterface}),
     * every requested member must be a declared field name; the first type with an
     * unrecognized member throws {@see \haddowg\JsonApi\Exception\FieldsetMemberUnrecognized}
     * (`400`).
     *
     * The known-member set is the resource's FULL declared namespace
     * (request-independent) — attributes and relationships, including hidden /
     * write-only / conditionally-hidden / non-sparse fields and `id` — so a member
     * is unknown only when it names no declared field at all (a hidden field name
     * and a bogus one are indistinguishable, no info leak).
     *
     * Two cases are deliberately TOLERATED (skipped): a `fields[type]` for an
     * unregistered / unresolvable type (out of scope — only members of KNOWN types
     * are validated), and a type whose serializer does NOT declare its field names
     * (a standalone bare serializer with no field inventory). The check runs from
     * the registry pre-render, independent of the transformed result, so a
     * `fields[type]` for an INCLUDED type — or any requested type even when the
     * primary result is empty — is still validated.
     *
     * @throws \haddowg\JsonApi\Exception\FieldsetMemberUnrecognized
     */
    private function validateStrictFieldsetMembers(
        \haddowg\JsonApi\Request\JsonApiRequestInterface $request,
    ): void {
        foreach ($request->requestedFieldsetTypes() as $type) {
            if (!$this->resources->hasSerializerFor($type)) {
                continue;
            }

            $serializer = $this->resources->serializerFor($type);
            if (!$serializer instanceof \haddowg\JsonApi\Serializer\DeclaresFieldNamesInterface) {
                continue;
            }

            $declared = \array_flip($serializer->declaredFieldNames());
            $unrecognized = [];
            foreach ($request->getIncludedFields($type) as $member) {
                // The empty-string sentinel means "render no fields of this type"
                // (a valid `?fields[type]=` request, also produced by a leading/
                // trailing/double comma) — it is not an unknown member. See
                // {@see JsonApiRequest::isIncludedField()}, which guards it likewise.
                if ($member === '') {
                    continue;
                }

                if (!isset($declared[$member])) {
                    $unrecognized[] = $member;
                }
            }

            if ($unrecognized !== []) {
                throw new \haddowg\JsonApi\Exception\FieldsetMemberUnrecognized($type, $unrecognized);
            }
        }
    }

    /**
     * Assembles the implementation-specific query-parameter family base names this
     * server recognizes for the given request: the host-registered
     * {@see withCustomQueryParameter()} names, and the reserved keywords of every
     * registered profile the client negotiated (so a profile's families — the
     * Relationship Queries `relatedQuery`/`rQ` and the Countable
     * `withCount` — are recognized only when its URI is requested, mirroring the gate
     * the profile's own parsers use).
     *
     * @return list<string>
     */
    private function recognizedCustomQueryParameters(
        \haddowg\JsonApi\Request\JsonApiRequestInterface $request,
    ): array {
        $families = [...$this->customQueryParameters];

        foreach ($this->profiles->all() as $profile) {
            if ($request->isProfileRequested($profile->uri())) {
                foreach ($profile->keywords() as $keyword) {
                    $families[] = $keyword;
                }
            }
        }

        return $families;
    }

    /**
     * Fires every registered `serving` handler once, in registration order,
     * before the operation handler runs. The JSON:API request is resolved from
     * the operation's {@see \haddowg\JsonApi\Operation\OperationContext}; a
     * programmatic dispatch with no HTTP message (or a non-JSON:API request) has
     * nothing to gate, so firing is skipped. A handler throwing a
     * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} propagates out
     * of {@see dispatch()} unchanged, so the operation handler never runs.
     */
    private function fireServing(\haddowg\JsonApi\Operation\JsonApiOperationInterface $operation): void
    {
        if ($this->serving === []) {
            return;
        }

        $request = $operation->context()->httpRequest();
        if (!$request instanceof \haddowg\JsonApi\Request\JsonApiRequestInterface) {
            return;
        }

        foreach ($this->serving as $serving) {
            $serving($request);
        }
    }

    /**
     * Normalises a PSR-11 container or a `callable(class-string): object` to one
     * `\Closure(class-string): object`.
     *
     * @param \Psr\Container\ContainerInterface|callable(class-string): object $resolver
     *
     * @return \Closure(class-string): object
     */
    private static function normalise(\Psr\Container\ContainerInterface|callable $resolver): \Closure
    {
        if ($resolver instanceof \Psr\Container\ContainerInterface) {
            return static function (string $class) use ($resolver): object {
                $instance = $resolver->get($class);

                if (!\is_object($instance)) {
                    throw new \LogicException(\sprintf(
                        'The container returned %s for "%s"; a JSON:API resolver must return an object.',
                        \get_debug_type($instance),
                        $class,
                    ));
                }

                return $instance;
            };
        }

        return \Closure::fromCallable($resolver);
    }

    private function innerHandler(): RequestHandlerInterface
    {
        $handler = $this->handler;
        if ($handler === null) {
            throw new \LogicException('No inner handler configured; call withHandler().');
        }

        if ($handler instanceof \haddowg\JsonApi\Operation\OperationHandlerInterface) {
            return new Psr7ToOperationHandlerAdapter(
                $handler,
                $this,
                strictQueryParameters: $this->validateStrictQueryParametersOf(...),
            );
        }

        return $handler;
    }
}
