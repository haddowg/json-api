<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Exception\MethodNotAllowed;
use haddowg\JsonApi\Exception\ResourceIdConflict;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Middleware\ErrorHandlerMiddleware;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\OperationReference;
use haddowg\JsonApi\OpenApi\SpecDescribedByResolver;
use haddowg\JsonApi\Operation\CustomActionOperation;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchRelationshipOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\OperationFactory;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Operation\UpdateRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\SingletonContext;
use haddowg\JsonApi\Resource\SingletonMisdeclared;
use haddowg\JsonApi\Resource\SingletonResourceInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Scope\ScopeEnforcer;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Serializer\UriTypeAwareInterface;
use haddowg\JsonApi\Server\ResourceRegistry;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Testing\SpecCompliance;
use haddowg\JsonApi\Transformer\ResourceTransformer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * A singleton type serves the caller's one resource at `/{uriType}`: the type resolves the
 * id, the ordinary fetch and update run with it, and its links hang off `/{uriType}` while
 * its resource objects keep their id.
 */
#[CoversClass(Target::class)]
#[CoversClass(OperationFactory::class)]
#[CoversClass(Server::class)]
#[CoversClass(ResourceRegistry::class)]
#[CoversClass(ResourceTransformer::class)]
#[CoversClass(AbstractRelationship::class)]
#[CoversClass(OperationReference::class)]
#[CoversClass(ScopeEnforcer::class)]
#[CoversClass(SingletonContext::class)]
#[CoversClass(SingletonMisdeclared::class)]
final class SingletonResourceTest extends TestCase
{
    protected function setUp(): void
    {
        SingletonInboxResource::$resolved = 0;
    }

    #[Test]
    public function aGetWithNoIdIsAFetchOfTheCallersResource(): void
    {
        $handler = new SingletonHandler();
        $document = $this->decode($this->send($handler, 'GET', '/inbox', Target::singleton('inboxes')));

        self::assertInstanceOf(FetchResourceOperation::class, $handler->operation);
        self::assertSame('42', $handler->operation->target()->id);
        self::assertTrue($handler->operation->target()->singleton);
        self::assertSame(1, SingletonInboxResource::$resolved);

        self::assertSame('inboxes', self::at($document, 'data', 'type'));
        self::assertSame('42', self::at($document, 'data', 'id'));
        self::assertSame(['self' => ['href' => 'https://api.test/inbox', 'describedby' => '/docs/openapi.json#/paths/~1inbox/get']], self::at($document, 'data', 'links'));
        self::assertSame([
            'self' => ['href' => 'https://api.test/inbox/relationships/owner', 'describedby' => '/docs/openapi.json#/paths/~1inbox~1relationships~1owner/get'],
            'related' => ['href' => 'https://api.test/inbox/owner', 'describedby' => '/docs/openapi.json#/paths/~1inbox~1owner/get'],
        ], self::at($document, 'data', 'relationships', 'owner', 'links'));
        self::assertSame(['unseen' => 3, 'unread' => 5, 'seenUpTo' => '2026-11-16T09:30:00Z'], self::at($document, 'data', 'attributes'));
    }

    #[Test]
    public function aTargetCarryingTheResolvedIdIsNotResolvedAgain(): void
    {
        $handler = new SingletonHandler();
        $this->send($handler, 'GET', '/inbox', Target::singleton('inboxes', '42'));

        self::assertSame(0, SingletonInboxResource::$resolved);
        self::assertSame('42', $handler->operation?->target()->id);
    }

    #[Test]
    public function anotherTypeLinksToItByIdAndIncludesIt(): void
    {
        $handler = new SingletonHandler();
        $document = $this->decode($this->send($handler, 'GET', '/inbox-items/7?include=inbox', new Target('inbox-items', '7'), ['include' => 'inbox']));

        self::assertSame(['links' => [
            'self' => ['href' => 'https://api.test/inbox-items/7/relationships/inbox', 'describedby' => '/docs/openapi.json#/paths/~1inbox-items~1%7Bid%7D~1relationships~1inbox/get'],
            'related' => ['href' => 'https://api.test/inbox-items/7/inbox', 'describedby' => '/docs/openapi.json#/paths/~1inbox-items~1%7Bid%7D~1inbox/get'],
        ], 'data' => ['type' => 'inboxes', 'id' => '42']], self::at($document, 'data', 'relationships', 'inbox'));

        self::assertSame('inboxes', self::at($document, 'included', '0', 'type'));
        self::assertSame('42', self::at($document, 'included', '0', 'id'));
        self::assertSame(['unseen' => 3, 'unread' => 5, 'seenUpTo' => '2026-11-16T09:30:00Z'], self::at($document, 'included', '0', 'attributes'));
        self::assertSame('https://api.test/inbox', self::at($document, 'included', '0', 'links', 'self', 'href'));
        self::assertSame('https://api.test/inbox/relationships/owner', self::at($document, 'included', '0', 'relationships', 'owner', 'links', 'self', 'href'));
        self::assertSame(0, SingletonInboxResource::$resolved);
    }

    #[Test]
    public function anotherTypesRelatedEndpointRendersItAsUsual(): void
    {
        $handler = new SingletonHandler();
        $response = $this->send($handler, 'GET', '/inbox-items/7/inbox', new Target('inbox-items', '7', 'inbox'));

        self::assertInstanceOf(FetchRelatedOperation::class, $handler->operation);
        self::assertSame('https://api.test/inbox', self::at($this->decode($response), 'data', 'links', 'self', 'href'));
    }

    #[Test]
    public function aCallerWithNoResourceGets404(): void
    {
        $response = $this->send(new SingletonHandler(), 'GET', '/inbox', Target::singleton('inboxes'), user: null);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('RESOURCE_NOT_FOUND', self::at($this->decode($response, 404), 'errors', '0', 'code'));
    }

    #[Test]
    public function aMissingRowIsTheDataLayers404(): void
    {
        $response = $this->send(new SingletonHandler(), 'GET', '/inbox', Target::singleton('inboxes'), user: 'gone');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function anUpdateRunsWithTheResolvedId(): void
    {
        $handler = new SingletonHandler();
        $response = $this->send($handler, 'PATCH', '/inbox', Target::singleton('inboxes'), body: ['data' => ['type' => 'inboxes', 'id' => '42', 'attributes' => ['seenUpTo' => '2026-11-16T10:00:00Z']]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertInstanceOf(UpdateResourceOperation::class, $handler->operation);
        self::assertSame('42', $handler->operation->target()->id);
        self::assertSame('https://api.test/inbox', self::at($this->decode($response), 'data', 'links', 'self', 'href'));
    }

    #[Test]
    public function anUpdateWhoseBodyIdIsNotTheCallersIsAConflict(): void
    {
        $handler = new SingletonHandler();
        $response = $this->send($handler, 'PATCH', '/inbox', Target::singleton('inboxes'), body: ['data' => ['type' => 'inboxes', 'id' => '43', 'attributes' => []]]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('RESOURCE_ID_CONFLICT', self::at($this->decode($response, 409), 'errors', '0', 'code'));
        self::assertNull($handler->operation);
    }

    #[Test]
    public function theFactoryComparesTheBodyIdWithTheResolvedId(): void
    {
        $request = new JsonApiRequest((new ServerRequest('PATCH', '/inbox', [], \json_encode(['data' => ['type' => 'inboxes', 'id' => '43']], \JSON_THROW_ON_ERROR)))->withAttribute('user', 'ada'));

        $this->expectException(ResourceIdConflict::class);

        (new OperationFactory())->fromRequest($request, Target::singleton('inboxes'), new OperationContext($this->server(new SingletonHandler()), $request));
    }

    #[Test]
    public function aCreateOrDeleteOnTheSingletonIsNotAllowed(): void
    {
        foreach (['POST', 'DELETE'] as $method) {
            $request = new JsonApiRequest((new ServerRequest($method, '/inbox'))->withAttribute('user', 'ada'));

            try {
                (new OperationFactory())->fromRequest($request, Target::singleton('inboxes'), new OperationContext($this->server(new SingletonHandler()), $request));
                self::fail($method . ' should be refused');
            } catch (MethodNotAllowed $e) {
                self::assertSame(['GET', 'PATCH'], $e->allowedMethods);
            }
        }
        self::assertSame(0, SingletonInboxResource::$resolved);
    }

    #[Test]
    public function aNullResolutionIsNotFoundFromTheFactory(): void
    {
        $request = new JsonApiRequest(new ServerRequest('GET', '/inbox'));

        $this->expectException(ResourceNotFound::class);

        (new OperationFactory())->fromRequest($request, Target::singleton('inboxes'), new OperationContext($this->server(new SingletonHandler()), $request));
    }

    #[Test]
    public function itsRelationshipEndpointsRunWithTheResolvedId(): void
    {
        $handler = new SingletonHandler();
        $server = $this->server($handler);
        $factory = new OperationFactory();

        $read = new JsonApiRequest((new ServerRequest('GET', '/inbox/relationships/owner'))->withAttribute('user', 'ada'));
        $operation = $factory->fromRequest($read, Target::singleton('inboxes', relationship: 'owner', isRelationshipEndpoint: true), new OperationContext($server, $read));
        self::assertInstanceOf(FetchRelationshipOperation::class, $operation);
        self::assertSame('42', $operation->target()->id);
        self::assertSame('/inbox/relationships/owner', OperationReference::forOperation($operation, $server)?->path);

        $related = $factory->fromRequest($read, Target::singleton('inboxes', relationship: 'owner'), new OperationContext($server, $read));
        self::assertInstanceOf(FetchRelatedOperation::class, $related);
        self::assertSame('/inbox/owner', OperationReference::forOperation($related, $server)?->path);

        $write = new JsonApiRequest((new ServerRequest('PATCH', '/inbox/relationships/owner', [], '{"data":{"type":"people","id":"ada"}}'))->withAttribute('user', 'ada'));
        $update = $factory->fromRequest($write, Target::singleton('inboxes', relationship: 'owner', isRelationshipEndpoint: true), new OperationContext($server, $write));
        self::assertInstanceOf(UpdateRelationshipOperation::class, $update);
        self::assertSame('42', $update->target()->id);
    }

    #[Test]
    public function itsActionsAreResourceScopedAtItsPath(): void
    {
        $server = $this->server(new SingletonHandler());
        $action = new CustomActionOperation(Target::singleton('inboxes', '42'), new QueryParameters([], [], [], [], []), new OperationContext($server), 'mark-all-read', 'POST');

        self::assertSame('/inbox/-actions/mark-all-read', OperationReference::forOperation($action, $server)?->path);
        [, $key] = ScopeEnforcer::keyFor($action);
        self::assertSame(ActionScope::Resource, $key->actionScope);
    }

    #[Test]
    public function aSingletonTargetIsAlwaysAFetchOneForItsScopes(): void
    {
        $server = $this->server(new SingletonHandler());
        $fetch = new FetchResourceOperation(Target::singleton('inboxes'), new QueryParameters([], [], [], [], []), new OperationContext($server));

        [$type, $key] = ScopeEnforcer::keyFor($fetch);
        self::assertSame('inboxes', $type);
        self::assertSame(OperationKind::FetchOne, $key->kind);
        self::assertSame('/inbox', OperationReference::forOperation($fetch, $server)?->path);
    }

    #[Test]
    public function theServerSaysWhichTypesAreSingletonsAndResolvesThem(): void
    {
        $server = $this->server(new SingletonHandler());

        self::assertTrue($server->isSingleton('inboxes'));
        self::assertFalse($server->isSingleton('inbox-items'));
        self::assertFalse($server->isSingleton('unknown'));
        self::assertSame('42', $server->resolveSingletonId('inboxes', new SingletonContext('inboxes', (new ServerRequest('GET', '/inbox'))->withAttribute('user', 'ada'))));
        self::assertNull($server->resolveSingletonId('inboxes', new SingletonContext('inboxes')));
        self::assertNull($server->resolveSingletonId('inbox-items', new SingletonContext('inbox-items')));
    }

    #[Test]
    public function theResolverReadsWhateverTheAdapterPasses(): void
    {
        $context = (new SingletonContext('inboxes'))->withAttribute('user', 'ada');

        self::assertSame('ada', $context->attribute('user'));
        self::assertSame('fallback', $context->attribute('tenant', 'fallback'));
        self::assertSame('42', $this->server(new SingletonHandler())->resolveSingletonId('inboxes', $context));
    }

    #[Test]
    public function aTypeComposedWithoutAResourceCanBeASingleton(): void
    {
        $psr17 = new Psr17Factory();
        $server = Server::make()
            ->withBaseUri('https://api.test')
            ->registerSerializerHydrator('settings', SingletonSettingsSerializer::class)
            ->withPsr17($psr17, $psr17)
            ->withHandler(new class implements OperationHandlerInterface {
                public function handle(JsonApiOperationInterface $operation): DataResponse
                {
                    return DataResponse::fromResource(['id' => $operation->target()->id], $operation->context()->server->serializerFor('settings'));
                }
            });

        self::assertTrue($server->isSingleton('settings'));

        $response = $server->handle((new ServerRequest('GET', '/settings'))->withAttribute(Target::class, Target::singleton('settings')));
        $document = $this->decode($response);

        self::assertSame('s-1', self::at($document, 'data', 'id'));
        self::assertSame(['href' => 'https://api.test/my-settings'], self::at($document, 'data', 'links', 'self'));
    }

    #[Test]
    public function aSingletonResourceMustSayHowToResolveTheCallersId(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is a singleton, so it must override resolveSingletonId()');

        Server::make()->register(UnresolvedSingletonResource::class);
    }

    #[Test]
    public function aSingletonCannotBeDeclaredWithACollectionCreateOrDelete(): void
    {
        SingletonMisdeclared::assertExclusive('inboxes', [OperationType::FetchOne, OperationType::Update]);
        SingletonMisdeclared::assertExclusive('inboxes', [OperationType::FetchOne]);

        try {
            SingletonMisdeclared::assertExclusive('inboxes', [OperationType::FetchCollection, OperationType::FetchOne, OperationType::Delete]);
            self::fail('A collection and delete should be refused.');
        } catch (SingletonMisdeclared $e) {
            self::assertSame('Type "inboxes" is a singleton, so it serves only fetch and update, but it declares FetchCollection, Delete. Declare a second type over the same entity for those operations; an entity can back several types.', $e->getMessage());
        }

        $this->expectException(SingletonMisdeclared::class);
        $this->expectExceptionMessage('Type "inboxes" is a singleton, so every action on it is resource-scoped, but it declares the collection-scope action "purge".');

        SingletonMisdeclared::assertExclusive('inboxes', [OperationType::FetchOne], ['purge']);
    }

    #[Test]
    public function aSingletonTargetAddressesOneResource(): void
    {
        $target = Target::singleton('inboxes');

        self::assertTrue($target->singleton);
        self::assertFalse($target->hasId());
        self::assertTrue($target->addressesResource());
        self::assertSame('42', $target->withId('42')->id);
        self::assertTrue($target->withId('42')->singleton);
        self::assertFalse((new Target('inboxes'))->addressesResource());
        self::assertTrue((new Target('inboxes', '1'))->addressesResource());
    }

    private function server(OperationHandlerInterface $handler): Server
    {
        $psr17 = new Psr17Factory();

        return Server::make()
            ->withBaseUri('https://api.test')
            ->register(SingletonInboxResource::class)
            ->register(SingletonInboxItemResource::class)
            ->register(SingletonPersonResource::class)
            ->withPsr17($psr17, $psr17)
            ->withLinkObjects()
            ->withDescribedByResolver(new SpecDescribedByResolver('/docs/openapi.json', [
                '/inbox' => ['get', 'patch'],
                '/inbox/owner' => ['get'],
                '/inbox/relationships/owner' => ['get', 'patch'],
                '/inbox-items/{id}' => ['get'],
                '/inbox-items/{id}/inbox' => ['get'],
                '/inbox-items/{id}/relationships/inbox' => ['get'],
            ]))
            ->withHandler($handler);
    }

    /**
     * @param array<string, string>     $query
     * @param array<string, mixed>|null $body
     */
    private function send(OperationHandlerInterface $handler, string $method, string $uri, Target $target, array $query = [], ?string $user = 'ada', ?array $body = null): ResponseInterface
    {
        $request = (new ServerRequest($method, $uri, ['Content-Type' => 'application/vnd.api+json'], $body === null ? null : \json_encode($body, \JSON_THROW_ON_ERROR)))
            ->withQueryParams($query)
            ->withAttribute(Target::class, $target);

        $server = $this->server($handler);

        return $server->withMiddleware([new ErrorHandlerMiddleware($server)])->handle($user === null ? $request : $request->withAttribute('user', $user));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response, int $status = 200): array
    {
        $body = (string) $response->getBody();
        self::assertSame($status, $response->getStatusCode(), $body);
        SpecCompliance::assert($body);

        $document = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}

final class SingletonHandler implements OperationHandlerInterface
{
    public ?JsonApiOperationInterface $operation = null;

    public function handle(JsonApiOperationInterface $operation): DataResponse
    {
        $this->operation = $operation;
        $server = $operation->context()->server;
        $target = $operation->target();
        $owner = (object) ['id' => 'ada', 'name' => 'Ada'];
        $inbox = static fn(string $id): object => (object) ['id' => $id, 'unseen' => 3, 'unread' => 5, 'seenUpTo' => '2026-11-16T09:30:00Z', 'owner' => $owner];

        if ($target->type === 'inbox-items') {
            $item = (object) ['id' => '7', 'title' => 'New follower', 'inbox' => $inbox('42')];

            return $target->relationship === 'inbox'
                ? DataResponse::fromResource($item->inbox, $server->serializerFor('inboxes'))
                : DataResponse::fromResource($item, $server->serializerFor('inbox-items'));
        }

        // The data layer finds no row for an id it doesn't hold.
        if ($target->id !== '42') {
            throw new ResourceNotFound();
        }

        return DataResponse::fromResource($inbox($target->id), $server->serializerFor('inboxes'));
    }
}

final class SingletonInboxResource extends AbstractResource
{
    public static string $type = 'inboxes';

    public static string $uriType = 'inbox';

    public static bool $singleton = true;

    public static int $resolved = 0;

    public function fields(): array
    {
        return [
            Id::make(),
            Integer::make('unseen')->readOnly(),
            Integer::make('unread')->readOnly(),
            Str::make('seenUpTo'),
            BelongsTo::make('owner', 'people'),
        ];
    }

    public function resolveSingletonId(SingletonContext $context): ?string
    {
        ++self::$resolved;
        $user = $context->request?->getAttribute('user') ?? $context->attribute('user');

        return match ($user) {
            'ada' => '42',
            'gone' => '99',
            default => null,
        };
    }
}

final class SingletonInboxItemResource extends AbstractResource
{
    public static string $type = 'inbox-items';

    public function fields(): array
    {
        return [Id::make(), Str::make('title'), BelongsTo::make('inbox', 'inboxes')];
    }
}

final class SingletonPersonResource extends AbstractResource
{
    public static string $type = 'people';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }
}

final class UnresolvedSingletonResource extends AbstractResource
{
    public static string $type = 'profiles';

    public static bool $singleton = true;

    public function fields(): array
    {
        return [Id::make()];
    }
}

final class SingletonSettingsSerializer extends AbstractSerializer implements SingletonResourceInterface, UriTypeAwareInterface
{
    public static function isSingleton(): bool
    {
        return true;
    }

    public function resolveSingletonId(SingletonContext $context): string
    {
        return 's-1';
    }

    public function uriType(): string
    {
        return 'my-settings';
    }

    public function getType(mixed $object): string
    {
        return 'settings';
    }

    public function getId(mixed $object): string
    {
        return \is_array($object) && \is_string($object['id'] ?? null) ? $object['id'] : '';
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
