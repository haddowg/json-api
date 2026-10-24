<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Atomic\AtomicResult;
use haddowg\JsonApi\Exception\ApplicationError;
use haddowg\JsonApi\OpenApi\DescribedByResolverInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\OperationReference;
use haddowg\JsonApi\OpenApi\SpecDescribedByResolver;
use haddowg\JsonApi\Operation\AtomicOperationsOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\OperationFactory;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Pagination\PageBasedPage;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\AtomicResultsResponse;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Schema\Link\Internal\LinkRendering;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Link\OperationLink;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\ResourceLinkContributorInterface;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The links a server builds render as JSON:API link objects, each described by the
 * OpenAPI operation of its target, through both entry points. An author's own links keep
 * their shape, and a server can opt back out to strings.
 */
#[CoversClass(LinkRendering::class)]
#[CoversClass(Server::class)]
#[CoversClass(OperationLink::class)]
#[Group('spec:document-links')]
final class LinkObjectRenderingTest extends TestCase
{
    private const HOST = 'https://music.example';

    private const DOCS = '/api/v5/docs.json';

    /**
     * The operations the served document carries. The tracks relationship endpoints are
     * left out, so their links render without `describedby`.
     */
    private const OPERATIONS = [
        '/records' => ['get'],
        '/records/{id}' => ['get'],
        '/records/{id}/artist' => ['get'],
        '/records/{id}/relationships/artist' => ['get'],
        '/records/{id}/-actions/publish' => ['post'],
        '/operations' => ['post'],
    ];

    #[Test]
    public function aResourceRendersEachBuiltLinkAsADescribedLinkObject(): void
    {
        $body = $this->handle($this->server(), '/records/1', new Target('albums', '1'), self::fetchAlbum(...));

        self::assertSame(
            [
                'alternate' => 'https://elsewhere.example/albums/1',
                'publish' => [
                    'href' => self::HOST . '/records/1/-actions/publish',
                    'describedby' => self::DOCS . '#/paths/~1records~1%7Bid%7D~1-actions~1publish/post',
                ],
                'self' => [
                    'href' => self::HOST . '/records/1',
                    'describedby' => self::DOCS . '#/paths/~1records~1%7Bid%7D/get',
                ],
            ],
            $this->at($body, 'data', 'links'),
        );

        self::assertSame(
            [
                'self' => [
                    'href' => self::HOST . '/records/1/relationships/artist',
                    'describedby' => self::DOCS . '#/paths/~1records~1%7Bid%7D~1relationships~1artist/get',
                ],
                'related' => [
                    'href' => self::HOST . '/records/1/artist',
                    'describedby' => self::DOCS . '#/paths/~1records~1%7Bid%7D~1artist/get',
                ],
            ],
            $this->at($body, 'data', 'relationships', 'artist', 'links'),
        );

        // The document doesn't carry the tracks endpoints, so their links aren't described.
        self::assertSame(
            ['self' => ['href' => self::HOST . '/records/1/relationships/tracks'], 'related' => ['href' => self::HOST . '/records/1/tracks']],
            $this->at($body, 'data', 'relationships', 'tracks', 'links'),
        );

        self::assertSame(
            [
                'self' => ['href' => self::HOST . '/records/1', 'describedby' => self::DOCS . '#/paths/~1records~1%7Bid%7D/get'],
                'describedby' => ['href' => self::DOCS . '#/paths/~1records~1%7Bid%7D/get'],
            ],
            $this->at($body, 'links'),
        );
    }

    #[Test]
    public function dispatchAndThePsr15PathRenderIdentically(): void
    {
        $server = $this->server();
        $target = new Target('albums', '1');
        $request = (new ServerRequest('GET', self::HOST . '/records/1'))->withAttribute(Target::class, $target);

        $viaHandle = $this->handle($server, '/records/1', $target, self::fetchAlbum(...));

        $served = $server->withHandler(new AlbumHandler(self::fetchAlbum(...)));
        $operation = (new OperationFactory())->fromRequest(new JsonApiRequest($request), $target, new OperationContext($served, $request));
        $viaDispatch = $this->decode((string) $served->dispatch($operation)->toPsrResponse($served, $request)->getBody());

        self::assertSame($viaHandle, $viaDispatch);
    }

    #[Test]
    public function thePaginationLinksAreDescribedByTheOperationThatProducedThePage(): void
    {
        $body = $this->handle(
            $this->server(),
            '/records?page[number]=2&page[size]=1',
            new Target('albums'),
            static fn(Server $server): DataResponse => DataResponse::fromPage(
                new PageBasedPage([self::album()], totalItems: 3, page: 2, size: 1),
                $server->serializerFor('albums'),
            ),
        );

        $collection = self::DOCS . '#/paths/~1records/get';
        foreach (['self', 'first', 'prev', 'next', 'last'] as $name) {
            self::assertIsArray($this->at($body, 'links', $name));
            self::assertSame($collection, $this->at($body, 'links', $name, 'describedby'), $name);
        }
        self::assertSame(self::HOST . '/records?page%5Bnumber%5D=3&page%5Bsize%5D=1', $this->at($body, 'links', 'next', 'href'));
        self::assertSame(['href' => $collection], $this->at($body, 'links', 'describedby'));
    }

    #[Test]
    public function aRelationshipDocumentIsDescribedByItsRelationshipEndpoint(): void
    {
        $body = $this->handle(
            $this->server(),
            '/records/1/relationships/artist',
            new Target('albums', '1', 'artist', true),
            static fn(Server $server): IdentifierResponse => IdentifierResponse::forRelationship(self::album(), $server->serializerFor('albums'), 'artist'),
        );

        $relationship = self::DOCS . '#/paths/~1records~1%7Bid%7D~1relationships~1artist/get';
        self::assertSame(['href' => self::HOST . '/records/1/relationships/artist', 'describedby' => $relationship], $this->at($body, 'links', 'self'));
        self::assertSame(['href' => $relationship], $this->at($body, 'links', 'describedby'));
    }

    #[Test]
    public function withoutAResolverLinksAreObjectsWithoutDescribedby(): void
    {
        $body = $this->handle($this->server(resolver: null), '/records/1', new Target('albums', '1'), self::fetchAlbum(...));

        self::assertSame(['href' => self::HOST . '/records/1'], $this->at($body, 'data', 'links', 'self'));
        self::assertSame(['href' => self::HOST . '/records/1/-actions/publish'], $this->at($body, 'data', 'links', 'publish'));
        self::assertSame(['href' => self::HOST . '/records/1/artist'], $this->at($body, 'data', 'relationships', 'artist', 'links', 'related'));
        self::assertSame(['self' => ['href' => self::HOST . '/records/1']], $this->at($body, 'links'));
    }

    #[Test]
    public function theOptOutRendersBareStrings(): void
    {
        $body = $this->handle($this->server()->withLinkObjects(false), '/records/1', new Target('albums', '1'), self::fetchAlbum(...));

        self::assertSame(self::HOST . '/records/1', $this->at($body, 'data', 'links', 'self'));
        self::assertSame(self::HOST . '/records/1/-actions/publish', $this->at($body, 'data', 'links', 'publish'));
        self::assertSame(self::HOST . '/records/1/relationships/artist', $this->at($body, 'data', 'relationships', 'artist', 'links', 'self'));
        self::assertSame(self::HOST . '/records/1', $this->at($body, 'links', 'self'));
        // The document still names the operation that describes it.
        self::assertSame(self::DOCS . '#/paths/~1records~1%7Bid%7D/get', $this->at($body, 'links', 'describedby'));
    }

    #[Test]
    public function anAbsoluteDocumentUrlIsUsedAsGiven(): void
    {
        $resolver = new SpecDescribedByResolver('https://docs.example/openapi.json', self::OPERATIONS);
        $body = $this->handle($this->server(resolver: $resolver), '/records/1', new Target('albums', '1'), self::fetchAlbum(...));

        self::assertSame('https://docs.example/openapi.json#/paths/~1records~1%7Bid%7D/get', $this->at($body, 'data', 'links', 'self', 'describedby'));
    }

    #[Test]
    public function anExplicitDescribedbyWinsOverTheOperations(): void
    {
        $body = $this->handle(
            $this->server(),
            '/records/1',
            new Target('albums', '1'),
            static fn(Server $server): DataResponse => DataResponse::fromResource(self::album(), $server->serializerFor('albums'))
                ->withDescribedby(new Link('/whole-spec.json')),
        );

        self::assertSame('/whole-spec.json', $this->at($body, 'links', 'describedby'));
    }

    #[Test]
    public function anErrorDocumentIsNotDescribedByTheOperation(): void
    {
        $server = $this->server();
        $target = new Target('albums', '1');
        $request = (new ServerRequest('GET', self::HOST . '/records/1'))->withAttribute(Target::class, $target);
        $served = $server->withHandler(new AlbumHandler(static fn(): ErrorResponse => ErrorResponse::fromException(new ApplicationError())));
        $operation = (new OperationFactory())->fromRequest(new JsonApiRequest($request), $target, new OperationContext($served, $request));

        $body = $this->decode((string) $served->dispatch($operation)->toPsrResponse($served, $request)->getBody());

        self::assertArrayHasKey('errors', $body);
        self::assertArrayNotHasKey('links', $body);
    }

    #[Test]
    public function aHandlerNamedOperationIsKept(): void
    {
        $body = $this->handle(
            $this->server(),
            '/records/1',
            new Target('albums', '1'),
            static fn(Server $server): DataResponse => DataResponse::fromResource(self::album(), $server->serializerFor('albums'))
                ->withOperationReference(OperationReference::fetchCollection('records')),
        );

        self::assertSame(['href' => self::DOCS . '#/paths/~1records/get'], $this->at($body, 'links', 'describedby'));
    }

    #[Test]
    public function anAtomicResultIsDescribedByTheAtomicEndpoint(): void
    {
        $body = $this->dispatchAtomic($this->server(), '/operations');

        self::assertSame(['describedby' => ['href' => self::DOCS . '#/paths/~1operations/post']], $this->at($body, 'links'));
        // The embedded resource keeps the shape its fragment was rendered with.
        self::assertSame(['self' => ['href' => self::HOST . '/records/1']], $this->at($body, 'atomic:results', 0, 'data', 'links'));
    }

    #[Test]
    public function anAtomicEndpointTheDocumentDoesNotCarryIsNotDescribed(): void
    {
        $body = $this->dispatchAtomic($this->server(), '/batch');

        self::assertArrayNotHasKey('links', $body);
    }

    #[Test]
    public function anAtomicOperationWithoutItsPathIsNotDescribed(): void
    {
        $body = $this->dispatchAtomic($this->server(), null);

        self::assertArrayNotHasKey('links', $body);
    }

    #[Test]
    public function anExplicitDescribedbyWinsOverTheAtomicEndpoint(): void
    {
        $body = $this->dispatchAtomic(
            $this->server(),
            '/operations',
            static fn(AtomicResultsResponse $response): AtomicResultsResponse => $response->withDescribedby(new Link('/whole-spec.json')),
        );

        self::assertSame(['describedby' => '/whole-spec.json'], $this->at($body, 'links'));
    }

    /**
     * @param (\Closure(AtomicResultsResponse): AtomicResultsResponse)|null $decorate
     *
     * @return array<string, mixed>
     */
    private function dispatchAtomic(Server $server, ?string $path, ?\Closure $decorate = null): array
    {
        $request = new ServerRequest('POST', self::HOST . '/operations');
        $served = $server->withHandler(new AtomicHandler($decorate ?? static fn(AtomicResultsResponse $response): AtomicResultsResponse => $response));
        $operation = new AtomicOperationsOperation([], new QueryParameters([], [], [], [], []), new OperationContext($served, $request), $path);

        $response = $served->dispatch($operation)->toPsrResponse($served, $request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode((string) $response->getBody());
    }

    private static function fetchAlbum(Server $server): DataResponse
    {
        return DataResponse::fromResource(self::album(), $server->serializerFor('albums'));
    }

    private function server(?DescribedByResolverInterface $resolver = new SpecDescribedByResolver(self::DOCS, self::OPERATIONS)): Server
    {
        $psr17 = new Psr17Factory();

        return Server::make()
            ->withPsr17($psr17, $psr17)
            ->register(LinkAlbumResource::class)
            ->register(LinkArtistResource::class)
            ->register(LinkTrackResource::class)
            ->withResourceLinkContributor(new PublishLinkContributor())
            ->withDescribedByResolver($resolver);
    }

    /**
     * @param \Closure(Server): (DataResponse|IdentifierResponse|ErrorResponse) $respond
     *
     * @return array<string, mixed>
     */
    private function handle(Server $server, string $path, Target $target, \Closure $respond): array
    {
        $request = (new ServerRequest('GET', self::HOST . $path))->withAttribute(Target::class, $target);
        \parse_str((string) \parse_url($path, \PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);

        $response = $server->withHandler(new AlbumHandler($respond))->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode((string) $response->getBody());
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        /** @var array<string, mixed> $body */
        $body = \json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $body;
    }

    private static function album(): object
    {
        return (object) [
            'id' => '1',
            'title' => 'Blue',
            'artist' => (object) ['id' => '7', 'name' => 'Joni'],
            'tracks' => [(object) ['id' => '10', 'title' => 'Carey']],
        ];
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function at(array $value, int|string ...$keys): mixed
    {
        $cursor = $value;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor);
            $cursor = $cursor[$key];
        }

        return $cursor;
    }


}

final readonly class AlbumHandler implements OperationHandlerInterface
{
    /**
     * @param \Closure(Server): (DataResponse|IdentifierResponse|ErrorResponse) $respond
     */
    public function __construct(private \Closure $respond) {}

    public function handle(JsonApiOperationInterface $operation): DataResponse|IdentifierResponse|ErrorResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        return ($this->respond)($server);
    }
}

final readonly class AtomicHandler implements OperationHandlerInterface
{
    /**
     * @param \Closure(AtomicResultsResponse): AtomicResultsResponse $decorate
     */
    public function __construct(private \Closure $decorate) {}

    public function handle(JsonApiOperationInterface $operation): AtomicResultsResponse
    {
        return ($this->decorate)(AtomicResultsResponse::fromResults([
            AtomicResult::fromDocument(['data' => ['type' => 'albums', 'id' => '1', 'links' => ['self' => ['href' => 'https://music.example/records/1']]]]),
        ]));
    }
}

final readonly class PublishLinkContributor implements ResourceLinkContributorInterface
{
    public function linksFor(mixed $object, string $type, JsonApiRequestInterface $request): array
    {
        if ($type !== 'albums' || !\is_object($object) || !\is_string($object->id ?? null)) {
            return [];
        }

        return [
            'publish' => new OperationLink(
                'https://music.example/records/' . $object->id . '/-actions/publish',
                OperationReference::action('records', 'publish', ActionScope::Resource, 'POST'),
            ),
        ];
    }
}

final class LinkAlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public static string $uriType = 'records';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            BelongsTo::make('artist', 'artists'),
            HasMany::make('tracks', 'tracks'),
        ];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ResourceLinks
    {
        return ResourceLinks::withoutBaseUri(links: ['alternate' => new Link('https://elsewhere.example/albums/1')]);
    }
}

final class LinkArtistResource extends AbstractResource
{
    public static string $type = 'artists';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }
}

final class LinkTrackResource extends AbstractResource
{
    public static string $type = 'tracks';

    public function fields(): array
    {
        return [Id::make(), Str::make('title')];
    }
}
