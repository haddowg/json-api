<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Exception\NoResourceRegistered;
use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Tests\Double\RecordingOperationHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(Server::class)]
#[CoversClass(\haddowg\JsonApi\Server\ResourceRegistry::class)]
#[CoversClass(\haddowg\JsonApi\Server\Entry::class)]
#[CoversClass(\haddowg\JsonApi\Server\Internal\MiddlewareDecorator::class)]
#[CoversClass(NoResourceRegistered::class)]
#[Group('spec:crud')]
final class ServerTest extends TestCase
{
    #[Test]
    public function defaultsAndFluentConfiguration(): void
    {
        $server = Server::make()
            ->withBaseUri('https://example.com/api/v1')
            ->withVersion('1.1')
            ->withDefaultMeta(['env' => 'test'])
            ->withEncodeOptions(\JSON_UNESCAPED_UNICODE);

        self::assertSame('https://example.com/api/v1', $server->baseUri());
        self::assertSame('1.1', $server->jsonApiVersion());
        self::assertSame(['env' => 'test'], $server->defaultMeta());
        self::assertSame(\JSON_UNESCAPED_UNICODE, $server->encodeOptions());
    }

    #[Test]
    public function withMethodsAreImmutable(): void
    {
        $base = Server::make();
        $configured = $base->withBaseUri('https://example.com');

        self::assertNotSame($base, $configured);
        self::assertSame('', $base->baseUri());
        self::assertSame('https://example.com', $configured->baseUri());
    }

    #[Test]
    public function registerIsImmutableAndDoesNotLeak(): void
    {
        $base = Server::make();
        $withPost = $base->register(PostResource::class);

        self::assertFalse($base->resources()->has('posts'));
        self::assertTrue($withPost->resources()->has('posts'));
    }

    #[Test]
    public function schemaSatisfiesBothContractsByDefault(): void
    {
        $server = Server::make()->register(PostResource::class);

        self::assertInstanceOf(PostResource::class, $server->serializerFor('posts'));
        self::assertInstanceOf(PostResource::class, $server->hydratorFor('posts'));
    }

    #[Test]
    public function serializerOverrideTakesPrecedence(): void
    {
        $server = Server::make()->register(PostResource::class, serializer: CustomPostSerializer::class);

        self::assertInstanceOf(CustomPostSerializer::class, $server->serializerFor('posts'));
        // Hydration still falls back to the schema.
        self::assertInstanceOf(PostResource::class, $server->hydratorFor('posts'));
    }

    #[Test]
    public function hydratorOverrideTakesPrecedence(): void
    {
        $server = Server::make()->register(PostResource::class, hydrator: CustomPostHydrator::class);

        self::assertInstanceOf(CustomPostHydrator::class, $server->hydratorFor('posts'));
        self::assertInstanceOf(PostResource::class, $server->serializerFor('posts'));
    }

    #[Test]
    public function unknownTypeThrowsNoResourceRegistered(): void
    {
        $server = Server::make()->register(PostResource::class);

        try {
            $server->serializerFor('widgets');
            self::fail('Expected NoResourceRegistered.');
        } catch (NoResourceRegistered $e) {
            self::assertSame('widgets', $e->type);
            self::assertSame(500, $e->getStatusCode());
        }
    }

    #[Test]
    public function duplicateTypeRegistrationThrows(): void
    {
        $this->expectException(\LogicException::class);

        Server::make()
            ->register(PostResource::class)
            ->register(PostResource::class);
    }

    #[Test]
    public function resolverConstructsResourcesLazily(): void
    {
        $resolver = new RecordingResolver();
        $server = Server::make()
            ->withContainer($resolver)
            ->register(PostResource::class);

        // Registration must not construct the resource: the type is read from the
        // static $type, so the resolver is untouched until first lookup.
        self::assertSame([], $resolver->calls);

        $first = $server->serializerFor('posts');
        self::assertInstanceOf(PostResource::class, $first);
        self::assertSame([PostResource::class], $resolver->calls);

        // Subsequent lookups are cached: the resolver is not consulted again, and
        // the same instance is handed back.
        $second = $server->resources()->resourceFor('posts');
        self::assertSame($first, $second);
        self::assertSame([PostResource::class], $resolver->calls);
    }

    #[Test]
    public function psr11ContainerResolvesRegisteredClasses(): void
    {
        $resource = new PostResource();
        $serializer = new CustomPostSerializer();
        $hydrator = new CustomPostHydrator();

        $container = new ArrayContainer([
            PostResource::class => $resource,
            CustomPostSerializer::class => $serializer,
            CustomPostHydrator::class => $hydrator,
        ]);

        $server = Server::make()
            ->withContainer($container)
            ->register(PostResource::class, serializer: CustomPostSerializer::class, hydrator: CustomPostHydrator::class);

        self::assertSame($serializer, $server->serializerFor('posts'));
        self::assertSame($hydrator, $server->hydratorFor('posts'));
        self::assertSame($resource, $server->resources()->resourceFor('posts'));
    }

    #[Test]
    public function withContainerIsImmutableAndDoesNotLeak(): void
    {
        $base = Server::make()->register(PostResource::class);
        $resolver = new RecordingResolver();
        $configured = $base->withContainer($resolver);

        self::assertNotSame($base, $configured);

        // The base server still uses plain `new` — the resolver did not leak in.
        self::assertInstanceOf(PostResource::class, $base->serializerFor('posts'));
        self::assertSame([], $resolver->calls);

        // The configured server resolves through the injected factory.
        self::assertInstanceOf(PostResource::class, $configured->serializerFor('posts'));
        self::assertSame([PostResource::class], $resolver->calls);
    }

    #[Test]
    public function withContainerIsOrderIndependent(): void
    {
        $before = new RecordingResolver();
        $serverBefore = Server::make()
            ->withContainer($before)
            ->register(PostResource::class);

        $after = new RecordingResolver();
        $serverAfter = Server::make()
            ->register(PostResource::class)
            ->withContainer($after);

        self::assertInstanceOf(PostResource::class, $serverBefore->serializerFor('posts'));
        self::assertInstanceOf(PostResource::class, $serverAfter->serializerFor('posts'));

        self::assertSame([PostResource::class], $before->calls);
        self::assertSame([PostResource::class], $after->calls);
    }

    #[Test]
    public function resolverReturningWrongTypeThrowsLogicException(): void
    {
        $server = Server::make()
            ->withContainer(static fn(string $class): object => new \stdClass())
            ->register(PostResource::class);

        $this->expectException(\LogicException::class);
        $server->serializerFor('posts');
    }

    #[Test]
    public function barePairResolvesSerializerAndHydratorWithoutResourceClass(): void
    {
        $server = Server::make()->registerSerializerHydrator(
            'widgets',
            serializer: CustomWidgetSerializer::class,
            hydrator: CustomPostHydrator::class,
        );

        self::assertTrue($server->hasSerializerFor('widgets'));
        self::assertInstanceOf(CustomWidgetSerializer::class, $server->serializerFor('widgets'));
        self::assertInstanceOf(CustomPostHydrator::class, $server->hydratorFor('widgets'));

        // A bare pair has no Resource fallback.
        $this->expectException(NoResourceRegistered::class);
        $server->resources()->resourceFor('widgets');
    }

    #[Test]
    public function barePairIsBuiltThroughTheInjectedResolver(): void
    {
        $resolver = new RecordingResolver();
        $server = Server::make()
            ->withContainer($resolver)
            ->registerSerializerHydrator('widgets', serializer: CustomWidgetSerializer::class);

        self::assertSame([], $resolver->calls);

        self::assertInstanceOf(CustomWidgetSerializer::class, $server->serializerFor('widgets'));
        self::assertSame([CustomWidgetSerializer::class], $resolver->calls);
    }

    #[Test]
    public function dispatchInvokesTheOperationHandler(): void
    {
        $response = MetaResponse::fromMeta(['ok' => true]);
        $handler = new RecordingOperationHandler($response);
        $server = Server::make()->withHandler($handler);

        $operation = $this->stubOperation();
        $result = $server->dispatch($operation);

        self::assertSame($response, $result);
        self::assertSame($operation, $handler->received);
    }

    #[Test]
    public function dispatchWithoutOperationHandlerThrows(): void
    {
        $server = Server::make();

        $this->expectException(\LogicException::class);
        $server->dispatch($this->stubOperation());
    }

    #[Test]
    public function handleRunsTheMiddlewareChainInOrder(): void
    {
        $psr17 = new Psr17Factory();
        $inner = new class ($psr17->createResponse(204)) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $order = $request->getAttribute('order');
                $order = \is_array($order) ? $order : [];
                $order[] = 'handler';
                $parts = \array_map(static fn(mixed $v): string => \is_string($v) ? $v : '', $order);

                return $this->response->withHeader('X-Order', \implode(',', $parts));
            }
        };

        $server = Server::make()
            ->withPsr17($psr17, $psr17)
            ->withMiddleware([new TaggingMiddleware('a'), new TaggingMiddleware('b')])
            ->withHandler($inner);

        $response = $server->handle(new ServerRequest('GET', '/api/posts'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('a,b,handler', $response->getHeaderLine('X-Order'));
    }

    #[Test]
    public function multipleServersRunTheirOwnConfiguration(): void
    {
        $psr17 = new Psr17Factory();
        $v1 = Server::make()
            ->withPsr17($psr17, $psr17)
            ->withMiddleware([new TaggingMiddleware('v1')])
            ->withHandler($this->orderEcho($psr17));
        $v2 = Server::make()
            ->withPsr17($psr17, $psr17)
            ->withMiddleware([new TaggingMiddleware('v2a'), new TaggingMiddleware('v2b')])
            ->withHandler($this->orderEcho($psr17));

        self::assertSame('v1,handler', $v1->handle(new ServerRequest('GET', '/v1'))->getHeaderLine('X-Order'));
        self::assertSame('v2a,v2b,handler', $v2->handle(new ServerRequest('GET', '/v2'))->getHeaderLine('X-Order'));
    }

    private function orderEcho(Psr17Factory $psr17): RequestHandlerInterface
    {
        return new class ($psr17->createResponse(200)) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $order = $request->getAttribute('order');
                $order = \is_array($order) ? $order : [];
                $order[] = 'handler';
                $parts = \array_map(static fn(mixed $v): string => \is_string($v) ? $v : '', $order);

                return $this->response->withHeader('X-Order', \implode(',', $parts));
            }
        };
    }

    private function stubOperation(): \haddowg\JsonApi\Operation\JsonApiOperationInterface
    {
        return new class implements \haddowg\JsonApi\Operation\JsonApiOperationInterface {
            public function target(): \haddowg\JsonApi\Operation\Target
            {
                return new \haddowg\JsonApi\Operation\Target('posts');
            }

            public function queryParameters(): \haddowg\JsonApi\Operation\QueryParameters
            {
                return new \haddowg\JsonApi\Operation\QueryParameters([], [], [], [], []);
            }

            public function context(): \haddowg\JsonApi\Operation\OperationContext
            {
                return new \haddowg\JsonApi\Operation\OperationContext(
                    new \haddowg\JsonApi\Tests\Double\StubServer(),
                );
            }
        };
    }
}

/**
 * Appends its tag to the request `order` attribute before delegating, so a
 * test can assert the chain ran in the configured order.
 */
final readonly class TaggingMiddleware implements MiddlewareInterface
{
    public function __construct(private string $tag) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $order = $request->getAttribute('order');
        $order = \is_array($order) ? $order : [];
        $order[] = $this->tag;

        return $handler->handle($request->withAttribute('order', $order));
    }
}

final class PostResource extends AbstractResource
{
    public static string $type = 'posts';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }
}

final class CustomPostSerializer extends AbstractSerializer
{
    public function getType(mixed $object): string
    {
        return 'posts';
    }

    public function getId(mixed $object): string
    {
        return '1';
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }
}

final class CustomPostHydrator implements HydratorInterface
{
    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        return $domainObject;
    }
}

final class CustomWidgetSerializer extends AbstractSerializer
{
    public function getType(mixed $object): string
    {
        return 'widgets';
    }

    public function getId(mixed $object): string
    {
        return '1';
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }
}

/**
 * A `callable(class-string): object` resolver that records every class it built
 * and constructs it with plain `new`, so a test can assert lazy, once-only
 * construction.
 */
final class RecordingResolver
{
    /**
     * @var list<class-string>
     */
    public array $calls = [];

    /**
     * @param class-string $class
     */
    public function __invoke(string $class): object
    {
        $this->calls[] = $class;

        return new $class();
    }
}

/**
 * A minimal PSR-11 container backed by a preconfigured class-string => instance
 * map, for exercising the container branch of the resolver.
 */
final class ArrayContainer implements \Psr\Container\ContainerInterface
{
    /**
     * @param array<class-string, object> $instances
     */
    public function __construct(private readonly array $instances) {}

    public function get(string $id): object
    {
        return $this->instances[$id]
            ?? throw new class ('No entry for ' . $id) extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {};
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]);
    }
}
