<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\ResourceLinkContributorInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Transformer\ResourceTransformer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Declared links rendered end to end through {@see Server::handle()}: their shape, how
 * they merge with the other links a resource carries, and how a name clash resolves.
 */
#[CoversClass(AbstractResource::class)]
#[CoversClass(ResourceTransformer::class)]
#[Group('spec:document-resource-object-links')]
final class DeclaredLinkRenderingTest extends TestCase
{
    #[Test]
    public function declaredLinksRenderBesideTheConventionSelf(): void
    {
        $links = $this->fetchLinks(Server::make()->withBaseUri('https://api.test'), DeclaredLinkFileResource::class, self::file('f-1', 'owner'));

        self::assertSame(
            [
                'upload' => [
                    'href' => 'https://uploads.example.com/f-1',
                    'title' => 'Upload',
                    'meta' => [
                        'method' => 'PUT',
                        'headers' => ['Content-Type' => 'image/png'],
                        'expiresAt' => '2026-10-26T12:00:00+00:00',
                    ],
                ],
                // A relative href takes the server's base, and a link with no meta or
                // static member stays a bare string while the built self is an object.
                'thumbnail' => 'https://api.test/files/f-1/thumbnail',
                'self' => ['href' => 'https://api.test/files/f-1'],
            ],
            $links,
        );
    }

    #[Test]
    public function aLinkWhoseHrefIsNullIsLeftOut(): void
    {
        $links = $this->fetchLinks(Server::make()->withBaseUri('https://api.test'), DeclaredLinkFileResource::class, self::file('f-1', 'guest'));

        self::assertSame(['thumbnail', 'self'], \array_keys($links));
    }

    #[Test]
    public function aNameGetLinksSetsWinsOverADeclaredLink(): void
    {
        $links = $this->fetchLinks(Server::make()->withBaseUri('https://api.test'), DeclaredLinkOverridingResource::class, self::file('f-1', 'owner'));

        self::assertSame('https://elsewhere.example.com/thumb.png', $links['thumbnail']);
    }

    #[Test]
    public function aDeclaredLinkWinsOverAContributedLinkOfTheSameName(): void
    {
        $contributor = new class implements ResourceLinkContributorInterface {
            public function linksFor(mixed $object, string $type, JsonApiRequestInterface $request): array
            {
                return ['thumbnail' => new Link('/contributed'), 'publish' => new Link('/contributed/publish')];
            }
        };

        $links = $this->fetchLinks(
            Server::make()->withBaseUri('https://api.test')->withResourceLinkContributor($contributor),
            DeclaredLinkFileResource::class,
            self::file('f-1', 'guest'),
        );

        self::assertSame('https://api.test/files/f-1/thumbnail', $links['thumbnail']);
        self::assertSame(['href' => 'https://api.test/contributed/publish'], $links['publish']);
    }

    #[Test]
    public function aServerRenderingStringLinksLeavesDeclaredLinkObjectsAlone(): void
    {
        $links = $this->fetchLinks(Server::make()->withBaseUri('https://api.test')->withLinkObjects(false), DeclaredLinkFileResource::class, self::file('f-1', 'owner'));

        self::assertSame('https://api.test/files/f-1', $links['self']);
        self::assertIsArray($links['upload']);
    }

    #[Test]
    public function temporalMetaFollowsTheServerOutputZone(): void
    {
        $links = $this->fetchLinks(Server::make()->withBaseUri('https://api.test')->withOutputTimezone('Asia/Tokyo'), DeclaredLinkFileResource::class, self::file('f-1', 'owner'));

        self::assertIsArray($links['upload']);
        self::assertIsArray($links['upload']['meta']);
        self::assertSame('2026-10-26T21:00:00+09:00', $links['upload']['meta']['expiresAt']);
    }

    #[Test]
    public function sparseFieldsetsDoNotTouchLinks(): void
    {
        $links = $this->fetchLinks(Server::make()->withBaseUri('https://api.test'), DeclaredLinkFileResource::class, self::file('f-1', 'owner'), ['fields' => ['files' => 'name']]);

        self::assertSame(['upload', 'thumbnail', 'self'], \array_keys($links));
    }

    private static function file(string $id, string $viewer): \stdClass
    {
        $file = new \stdClass();
        $file->id = $id;
        $file->name = 'cover.png';
        $file->viewer = $viewer;

        return $file;
    }

    /**
     * @param class-string<AbstractResource> $resource
     * @param array<string, mixed>           $query
     *
     * @return array<string, mixed>
     */
    private function fetchLinks(Server $server, string $resource, object $file, array $query = []): array
    {
        $psr17 = new Psr17Factory();
        $handler = new class ($file) implements OperationHandlerInterface {
            public function __construct(private readonly object $file) {}

            public function handle(JsonApiOperationInterface $operation): DataResponse
            {
                return DataResponse::fromResource($this->file, $operation->context()->server->serializerFor('files'));
            }
        };

        $response = $server
            ->register($resource)
            ->withPsr17($psr17, $psr17)
            ->withHandler($handler)
            ->handle(
                (new ServerRequest('GET', '/files/f-1' . ($query === [] ? '' : '?' . \http_build_query($query))))
                    ->withQueryParams($query)
                    ->withAttribute(Target::class, new Target('files', 'f-1')),
            );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array{data: array{links: array<string, mixed>}} $body */
        $body = \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);

        return $body['data']['links'];
    }
}

class DeclaredLinkFileResource extends AbstractResource
{
    public static string $type = 'files';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }

    public function links(): array
    {
        return [
            DeclaredLink::make('upload')
                ->title('Upload')
                ->meta(
                    Str::make('method'),
                    ArrayHash::make('headers'),
                    DateTime::make('expiresAt'),
                )
                ->hrefUsing(static fn(mixed $file, JsonApiRequestInterface $request): ?string => $file instanceof \stdClass && $file->viewer === 'owner'
                    ? 'https://uploads.example.com/' . $file->id
                    : null)
                ->metaUsing(static fn(mixed $file, JsonApiRequestInterface $request): array => [
                    'method' => 'PUT',
                    'headers' => ['Content-Type' => 'image/png'],
                    'expiresAt' => new \DateTimeImmutable('2026-10-26 12:00:00', new \DateTimeZone('UTC')),
                ]),
            DeclaredLink::make('thumbnail')
                ->hrefUsing(static fn(mixed $file, JsonApiRequestInterface $request): ?string => $file instanceof \stdClass ? '/files/' . $file->id . '/thumbnail' : null),
        ];
    }
}

final class DeclaredLinkOverridingResource extends DeclaredLinkFileResource
{
    public function getLinks(mixed $object, JsonApiRequestInterface $request): ResourceLinks
    {
        return ResourceLinks::withoutBaseUri(links: ['thumbnail' => new Link('https://elsewhere.example.com/thumb.png')]);
    }
}
