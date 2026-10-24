<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationReference;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\OpenApi\SpecDescribedByResolver;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A link's `describedby` is a JSON Pointer fragment into the served document, built from
 * the same path templates the projector keys `paths` by.
 */
#[CoversClass(OperationReference::class)]
#[CoversClass(SpecDescribedByResolver::class)]
final class OperationReferenceTest extends TestCase
{
    #[Test]
    public function aPathWithAnIdIsEscapedAndPercentEncoded(): void
    {
        $operation = OperationReference::fetchOne('albums');

        self::assertSame('/paths/~1albums~1{id}/get', $operation->pointer());
        self::assertSame('/paths/~1albums~1%7Bid%7D/get', $operation->fragment());
    }

    #[Test]
    public function aNestedRelationshipPathEscapesEverySegment(): void
    {
        self::assertSame(
            '/paths/~1albums~1%7Bid%7D~1relationships~1tracks/get',
            OperationReference::fetchRelationship('albums', 'tracks')->fragment(),
        );
        self::assertSame(
            '/paths/~1albums~1%7Bid%7D~1tracks/get',
            OperationReference::fetchRelated('albums', 'tracks')->fragment(),
        );
    }

    #[Test]
    public function aTildeIsEscapedBeforeASlash(): void
    {
        self::assertSame('/paths/~1a~0b/get', OperationReference::fetchCollection('a~b')->pointer());
    }

    #[Test]
    public function anActionNamesItsScopedPathAndMethod(): void
    {
        self::assertSame(
            '/paths/~1albums~1%7Bid%7D~1-actions~1publish/post',
            OperationReference::action('albums', 'publish', ActionScope::Resource, 'POST')->fragment(),
        );
        self::assertSame(
            '/paths/~1albums~1-actions~1import/post',
            OperationReference::action('albums', 'import', ActionScope::Collection, 'post')->fragment(),
        );
    }

    #[Test]
    public function aPrefixedMountKeepsTheMountInTheDocumentUrlNotTheFragment(): void
    {
        // The projector keys paths relative to the server URL, so a server mounted at
        // `/api/v5` still keys `/albums/{id}`; the mount lives in the document's own URL.
        $resolver = new SpecDescribedByResolver('/api/v5/docs.json', ['/albums/{id}' => ['get']]);

        self::assertSame(
            '/api/v5/docs.json#/paths/~1albums~1%7Bid%7D/get',
            $resolver->describedBy(OperationReference::fetchOne('albums')),
        );
    }

    #[Test]
    public function anAbsoluteDocumentUrlIsUsedAsGiven(): void
    {
        $resolver = new SpecDescribedByResolver('https://docs.example/openapi.json#ignored', ['/albums' => ['get']]);

        self::assertSame('https://docs.example/openapi.json', $resolver->documentUrl());
        self::assertSame(
            'https://docs.example/openapi.json#/paths/~1albums/get',
            $resolver->describedBy(OperationReference::fetchCollection('albums')),
        );
    }

    #[Test]
    public function anOperationTheDocumentDoesNotCarryResolvesToNull(): void
    {
        $resolver = new SpecDescribedByResolver('/docs.json', ['/albums' => ['get']]);

        self::assertNull($resolver->describedBy(OperationReference::create('albums')));
        self::assertNull($resolver->describedBy(OperationReference::fetchOne('albums')));
    }

    #[Test]
    public function aResolverBuiltFromTheMetadataPointsOnlyAtProjectedOperations(): void
    {
        $albums = FakeTypeMetadata::resource(
            'albums',
            [Id::make()->build()],
            relations: [new FakeRelationMetadata('tracks', ['albums'], true, relatedEndpoint: false)],
            uriType: 'records',
            operations: [OperationType::FetchOne],
            actions: [new FakeActionMetadata('publish', ['POST'])],
        );
        $metadata = new FakeServerMetadata('Music', '1', [$albums], servers: [new Server('/api/v5')]);

        $resolver = SpecDescribedByResolver::fromMetadata('/api/v5/docs.json', $metadata);
        $document = (new OpenApiProjector())->project($metadata)->toArray();
        self::assertIsArray($document['paths']);

        foreach ([
            OperationReference::fetchOne('records'),
            OperationReference::fetchRelationship('records', 'tracks'),
            OperationReference::action('records', 'publish', ActionScope::Resource, 'post'),
        ] as $operation) {
            $describedBy = $resolver->describedBy($operation);
            self::assertNotNull($describedBy);
            self::assertArrayHasKey($operation->path, $document['paths']);
            self::assertIsArray($document['paths'][$operation->path]);
            self::assertArrayHasKey($operation->method, $document['paths'][$operation->path]);
        }

        self::assertNull($resolver->describedBy(OperationReference::fetchCollection('records')));
        self::assertNull($resolver->describedBy(OperationReference::fetchRelated('records', 'tracks')));
        self::assertNull($resolver->describedBy(OperationReference::fetchOne('albums')));
    }
}
