<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\LinkSchemaProjector;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresRelationUriFieldName;
use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\OperationReference;
use haddowg\JsonApi\OpenApi\SpecDescribedByResolver;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\OperationFactory;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\LinkDeclaringServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\UriFieldNameRelationMetadata;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relation served under a URI segment other than its name
 * ({@see DeclaresRelationUriFieldName}, `withUriFieldName()`) is documented, linked and
 * described under that segment, so the paths, the links and their `describedby` agree.
 */
#[CoversClass(OperationProjector::class)]
#[CoversClass(LinkSchemaProjector::class)]
#[CoversClass(OperationReference::class)]
#[CoversClass(AbstractResource::class)]
final class RelationUriFieldNameTest extends TestCase
{
    private const HOST = 'https://music.example';

    private const DOCS = '/docs.json';

    #[Test]
    public function theRelationsPathsAreKeyedByItsUriFieldName(): void
    {
        $paths = $this->arrAt($this->document()->toArray(), 'paths');

        self::assertArrayHasKey('/records/{id}/by', $paths);
        self::assertArrayHasKey('/records/{id}/relationships/by', $paths);
        self::assertArrayNotHasKey('/records/{id}/artist', $paths);
        self::assertArrayNotHasKey('/records/{id}/relationships/artist', $paths);

        // The operation and the relationship member keep the relation's name.
        self::assertSame('fetchRelated.albums.artist', $this->at($paths, '/records/{id}/by', 'get', 'operationId'));
        $relationships = $this->arrAt($this->document()->toArray(), 'components', 'schemas', 'AlbumsResource', 'properties', 'relationships', 'properties');
        self::assertArrayHasKey('artist', $relationships);
    }

    #[Test]
    public function theRelationshipLinksPointAtTheUriFieldNameAndAreDescribed(): void
    {
        $links = $this->arrAt($this->document()->toArray(), 'paths', '/records/{id}', 'get', 'responses', '200', 'content', 'application/vnd.api+json', 'example', 'data', 'relationships', 'artist', 'links');

        self::assertSame(
            ['href' => '/records/1/relationships/by', 'describedby' => self::DOCS . '#/paths/~1records~1%7Bid%7D~1relationships~1by/get'],
            $this->at($links, 'self'),
        );
        self::assertSame(
            ['href' => '/records/1/by', 'describedby' => self::DOCS . '#/paths/~1records~1%7Bid%7D~1by/get'],
            $this->at($links, 'related'),
        );
    }

    #[Test]
    public function aRenderedResourcesRelationshipLinksResolveTheirDescribedby(): void
    {
        $body = $this->dispatch(new Target('albums', '1'));

        self::assertSame(
            [
                'self' => [
                    'href' => self::HOST . '/records/1/relationships/by',
                    'describedby' => self::DOCS . '#/paths/~1records~1%7Bid%7D~1relationships~1by/get',
                ],
                'related' => [
                    'href' => self::HOST . '/records/1/by',
                    'describedby' => self::DOCS . '#/paths/~1records~1%7Bid%7D~1by/get',
                ],
            ],
            $this->at($body, 'data', 'relationships', 'artist', 'links'),
        );
    }

    #[Test]
    public function aRelatedDocumentIsDescribedByTheEndpointUnderTheUriFieldName(): void
    {
        $body = $this->dispatch(new Target('albums', '1', 'artist'));

        self::assertSame(['href' => self::DOCS . '#/paths/~1records~1%7Bid%7D~1by/get'], $this->at($body, 'links', 'describedby'));
    }

    #[Test]
    public function aHostResolvesTheSegmentToTheRelationServedThere(): void
    {
        $albums = new UriAlbumResource();

        self::assertSame('artist', $albums->relationAtUriFieldName('by')?->name());
        self::assertSame('label', $albums->relationAtUriFieldName('label')?->name());
        // Renamed in the URI, so not served under its name.
        self::assertNull($albums->relationAtUriFieldName('artist'));
        self::assertNull($albums->relationAtUriFieldName('nope'));
    }

    private function document(): OpenApi
    {
        $albums = FakeTypeMetadata::resource(
            type: 'albums',
            fields: [Id::make()->example('1')->build(), Str::make('title')->example('Kind of Blue')->build()],
            relations: [
                new UriFieldNameRelationMetadata(new FakeRelationMetadata('artist', ['artists'], false), 'by'),
                new FakeRelationMetadata('label', ['labels'], false),
            ],
            uriType: 'records',
        );
        $server = new FakeServerMetadata('Music', '1', [
            $albums,
            FakeTypeMetadata::resource('artists', [Id::make()->example('2')->build(), Str::make('name')->example('Miles Davis')->build()]),
            FakeTypeMetadata::resource('labels', [Id::make()->example('3')->build()]),
        ]);

        return (new OpenApiProjector())->project(new LinkDeclaringServerMetadata($server, documentUrl: self::DOCS));
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatch(Target $target): array
    {
        $psr17 = new Psr17Factory();
        $server = Server::make()
            ->withPsr17($psr17, $psr17)
            ->register(UriAlbumResource::class)
            ->register(UriArtistResource::class)
            ->register(UriLabelResource::class)
            ->withDescribedByResolver(SpecDescribedByResolver::fromDocument(self::DOCS, $this->document()))
            ->withHandler(new UriAlbumHandler());

        $request = new ServerRequest('GET', self::HOST . '/records/1');
        $context = new OperationContext($server, $request);
        $operation = (new OperationFactory())->fromRequest(new JsonApiRequest($request), $target, $context);

        $response = $server->dispatch($operation)->toPsrResponse($server, $request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array<string, mixed> $body */
        $body = \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);

        return $body;
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

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function arrAt(array $value, int|string ...$keys): array
    {
        $found = $this->at($value, ...$keys);
        self::assertIsArray($found);

        return $found;
    }
}

final readonly class UriAlbumHandler implements OperationHandlerInterface
{
    public function handle(JsonApiOperationInterface $operation): DataResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        $album = (object) ['id' => '1', 'title' => 'Blue', 'artist' => (object) ['id' => '7', 'name' => 'Joni'], 'label' => null];

        return $operation instanceof FetchRelatedOperation
            ? DataResponse::fromResource($album->artist, $server->serializerFor('artists'))
            : DataResponse::fromResource($album, $server->serializerFor('albums'));
    }
}

final class UriAlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public static string $uriType = 'records';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            BelongsTo::make('artist', 'artists')->withUriFieldName('by'),
            BelongsTo::make('label', 'labels'),
        ];
    }
}

final class UriArtistResource extends AbstractResource
{
    public static string $type = 'artists';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }
}

final class UriLabelResource extends AbstractResource
{
    public static string $type = 'labels';

    public function fields(): array
    {
        return [Id::make()];
    }
}
