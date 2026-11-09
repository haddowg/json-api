<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Server\ResourceRegistry;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Testing\SpecCompliance;
use haddowg\JsonApi\Transformer\ResourceTransformer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A related-only type ({@see Server::withRelatedOnlyTypes()}) serves no endpoint of its
 * own, so its resources render no convention `self` link and its relationship objects
 * no `self` or `related` link, while it still renders as an included resource.
 */
#[CoversClass(Server::class)]
#[CoversClass(ResourceRegistry::class)]
#[CoversClass(ResourceTransformer::class)]
#[CoversClass(AbstractRelationship::class)]
final class RelatedOnlyTypeRenderingTest extends TestCase
{
    #[Test]
    public function anIncludedRelatedOnlyResourceRendersNoLinksToItsOwnEndpoints(): void
    {
        $document = $this->fetch(Server::make()->withRelatedOnlyTypes('labels'));

        self::assertSame(['self' => ['href' => 'https://api.test/albums/1']], self::at($document, 'data', 'links'));
        // The album's own relationship still links to its endpoints.
        self::assertSame([
            'self' => ['href' => 'https://api.test/albums/1/relationships/label'],
            'related' => ['href' => 'https://api.test/albums/1/label'],
        ], self::at($document, 'data', 'relationships', 'label', 'links'));

        self::assertSame('labels', self::at($document, 'included', '0', 'type'));
        self::assertNull(self::at($document, 'included', '0', 'links'));
        // With no links to render, the relationship object carries its linkage instead.
        self::assertSame(['data' => ['type' => 'labels', 'id' => 'l0']], self::at($document, 'included', '0', 'relationships', 'parent'));
    }

    #[Test]
    public function aTypeNotMarkedRelatedOnlyKeepsItsLinks(): void
    {
        $document = $this->fetch(Server::make());

        self::assertSame(['self' => ['href' => 'https://api.test/labels/l1']], self::at($document, 'included', '0', 'links'));
        self::assertNotNull(self::at($document, 'included', '0', 'relationships', 'parent', 'links'));
    }

    #[Test]
    public function aSelfLinkTheResourceSuppliesStillRenders(): void
    {
        $document = $this->fetch(Server::make()->withRelatedOnlyTypes('labels'), labelLinks: true);

        self::assertSame(['self' => 'https://labels.example/l1'], self::at($document, 'included', '0', 'links'));
    }

    #[Test]
    public function theServerSaysWhichTypesAreRelatedOnly(): void
    {
        $server = Server::make()->withRelatedOnlyTypes('labels', 'labels');

        self::assertTrue($server->isRelatedOnly('labels'));
        self::assertFalse($server->isRelatedOnly('albums'));
        self::assertFalse(Server::make()->isRelatedOnly('labels'));
        self::assertFalse($server->withRelatedOnlyTypes()->isRelatedOnly('labels'));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(Server $server, bool $labelLinks = false): array
    {
        $psr17 = new Psr17Factory();
        $handler = new class implements OperationHandlerInterface {
            public function handle(JsonApiOperationInterface $operation): DataResponse
            {
                $parent = (object) ['id' => 'l0', 'name' => 'Parent', 'parent' => null];
                $label = (object) ['id' => 'l1', 'name' => 'Island', 'parent' => $parent];
                $album = (object) ['id' => '1', 'title' => 'Debut', 'label' => $label];

                return DataResponse::fromResource($album, $operation->context()->server->serializerFor('albums'));
            }
        };

        $response = $server
            ->withBaseUri('https://api.test')
            ->register(RelatedOnlyAlbumResource::class)
            ->register($labelLinks ? RelatedOnlyLinkedLabelResource::class : RelatedOnlyLabelResource::class)
            ->withPsr17($psr17, $psr17)
            ->withHandler($handler)
            ->handle((new ServerRequest('GET', '/albums/1?include=label', [], null, '1.1', []))
                ->withQueryParams(['include' => 'label'])
                ->withAttribute(Target::class, new Target('albums', '1')));

        $body = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode(), $body);
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

final class RelatedOnlyAlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [Id::make(), Str::make('title'), BelongsTo::make('label', 'labels')];
    }
}

class RelatedOnlyLabelResource extends AbstractResource
{
    public static string $type = 'labels';

    public function fields(): array
    {
        return [Id::make(), Str::make('name'), BelongsTo::make('parent', 'labels')->nullable()];
    }
}

final class RelatedOnlyLinkedLabelResource extends RelatedOnlyLabelResource
{
    public function getLinks(mixed $object, JsonApiRequestInterface $request): ResourceLinks
    {
        \assert(\is_object($object) && \property_exists($object, 'id') && \is_string($object->id));

        return new ResourceLinks('', new Link('https://labels.example/' . $object->id));
    }
}
