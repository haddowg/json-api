<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Server;

use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Operation\JsonApiOperation;
use haddowg\JsonApi\Operation\OperationHandler;
use haddowg\JsonApi\Operation\Psr7ToOperationHandlerAdapter;
use haddowg\JsonApi\Pagination\Paginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\SerializerResolver;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\MetaResponse;
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
final class Server implements ServerInterface, RequestHandlerInterface, SerializerResolver
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

    private ?Paginator $defaultPaginator = null;

    private ?ResponseFactoryInterface $responseFactory = null;

    private ?StreamFactoryInterface $streamFactory = null;

    /**
     * @var list<MiddlewareInterface>
     */
    private array $middleware = [];

    private OperationHandler|RequestHandlerInterface|null $handler = null;

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

    public function withDefaultPaginator(?Paginator $paginator): self
    {
        $self = clone $this;
        $self->defaultPaginator = $paginator;

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
        $self->resources->register($resource, $serializer, $hydrator);

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
    public function withHandler(OperationHandler|RequestHandlerInterface $handler): self
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

    public function resources(): ResourceRegistry
    {
        return $this->resources;
    }

    public function defaultPaginator(): ?Paginator
    {
        return $this->defaultPaginator;
    }

    public function serializerFor(string $type): SerializerInterface
    {
        return $this->resources->serializerFor($type);
    }

    public function hasSerializerFor(string $type): bool
    {
        return $this->resources->hasSerializerFor($type);
    }

    public function hydratorFor(string $type): HydratorInterface
    {
        return $this->resources->hydratorFor($type);
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
        JsonApiOperation $operation,
    ): DataResponse|MetaResponse|RelatedResponse|IdentifierResponse|ErrorResponse {
        $handler = $this->handler;
        if (!$handler instanceof OperationHandler) {
            throw new \LogicException('Server::dispatch() requires an OperationHandler; call withHandler().');
        }

        return $handler->handle($operation);
    }

    private function innerHandler(): RequestHandlerInterface
    {
        $handler = $this->handler;
        if ($handler === null) {
            throw new \LogicException('No inner handler configured; call withHandler().');
        }

        if ($handler instanceof OperationHandler) {
            return new Psr7ToOperationHandlerAdapter($handler, $this);
        }

        return $handler;
    }
}
