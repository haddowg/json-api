<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Transformer;

use haddowg\JsonApi\Exception\InclusionUnrecognized;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationship;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Tests\Double\DummyData;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Tests\Double\StubSelfLinkResource;
use haddowg\JsonApi\Transformer\ResourceTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:inclusion-of-related-resources')]
#[Group('spec:sparse-fieldsets')]
final class ResourceTransformerTest extends TestCase
{
    #[Test]
    public function transformToResourceIdentifierWhenObjectIsNull(): void
    {
        $resource = $this->createResource();

        $resourceIdentifier = $this->toResourceIdentifier($resource, null);

        self::assertNull($resourceIdentifier);
    }

    #[Test]
    public function transformToResourceIdentifierWhenObjectIsNotNull(): void
    {
        $resource = $this->createResource('user', '1');

        $resourceIdentifier = $this->toResourceIdentifier($resource, []);

        self::assertEquals(
            [
                'type' => 'user',
                'id' => '1',
            ],
            $resourceIdentifier,
        );
    }

    #[Test]
    public function transformToResourceIdentifierWithMeta(): void
    {
        $resource = $this->createResource('user', '1', ['abc' => 'def']);

        $resourceIdentifier = $this->toResourceIdentifier($resource, []);

        self::assertEquals(
            [
                'type' => 'user',
                'id' => '1',
                'meta' => ['abc' => 'def'],
            ],
            $resourceIdentifier,
        );
    }

    #[Test]
    public function transformToResourceObjectWhenNull(): void
    {
        $resource = $this->createResource('user', '1');

        $resourceObject = $this->toResourceObject($resource, null);

        self::assertNull($resourceObject);
    }

    #[Test]
    public function transformToResourceObjectWhenAlmostEmpty(): void
    {
        $resource = $this->createResource('user', '1');

        $resourceObject = $this->toResourceObject($resource, []);

        self::assertEquals(
            [
                'type' => 'user',
                'id' => '1',
                'links' => ['self' => '/user/1'],
            ],
            $resourceObject,
        );
    }

    #[Test]
    public function transformToResourceObjectWithMeta(): void
    {
        $resource = $this->createResource('', '', ['abc' => 'def']);

        $resourceObject = $this->toResourceObject($resource, []);

        self::assertEquals(
            [
                'type' => '',
                'id' => '',
                'meta' => ['abc' => 'def'],
            ],
            $resourceObject,
        );
    }

    #[Test]
    public function transformToResourceObjectWithLinks(): void
    {
        $resource = $this->createResource('', '', [], new ResourceLinks());

        $resourceObject = $this->toResourceObject($resource, []);

        self::assertEquals(
            [
                'type' => '',
                'id' => '',
                'links' => [],
            ],
            $resourceObject,
        );
    }

    #[Test]
    public function transformToResourceObjectWithMetaAndLinks(): void
    {
        $resource = $this->createResource('user', '1', ['abc' => 'def'], new ResourceLinks());

        $resourceObject = $this->toResourceObject($resource, []);

        self::assertEquals(
            [
                'type' => 'user',
                'id' => '1',
                'meta' => ['abc' => 'def'],
                'links' => ['self' => '/user/1'],
            ],
            $resourceObject,
        );
    }

    #[Test]
    public function transformToResourceObjectWithAttributes(): void
    {
        $resource = $this->createResource(
            'user',
            '1',
            ['abc' => 'def'],
            new ResourceLinks(),
            [
                'full_name' => static fn(array $object, JsonApiRequestInterface $request): string => $object['name'],
                'birth' => static fn(array $object): int => 2015 - (int) $object['age'],
            ],
        );

        $resourceObject = $this->toResourceObject(
            $resource,
            [
                'name' => 'John Doe',
                'age' => '30',
            ],
        );

        self::assertEquals(
            [
                'type' => 'user',
                'id' => '1',
                'meta' => ['abc' => 'def'],
                'links' => ['self' => '/user/1'],
                'attributes' => [
                    'full_name' => 'John Doe',
                    'birth' => 1985,
                ],
            ],
            $resourceObject,
        );
    }

    #[Test]
    #[Group('spec:document-resource-object-links')]
    public function emitsTheConventionSelfLinkForAResourceWithAnId(): void
    {
        $resource = new StubResource('user', '1');

        $resourceObject = $this->toResourceObject($resource, [], null, 'https://api.test');

        self::assertSame(
            [
                'type' => 'user',
                'id' => '1',
                'links' => ['self' => 'https://api.test/user/1'],
            ],
            $resourceObject,
        );
    }

    #[Test]
    #[Group('spec:document-resource-object-links')]
    public function theConventionSelfLinkUsesTheUriTypeWhenItDiffersFromTheType(): void
    {
        // JSON:API type `book`, URL segment `books` — the self link's path segment
        // is the uriType, while the resource object still carries `"type": "book"`.
        $resource = new StubSelfLinkResource(type: 'book', id: '7', uriType: 'books');

        $resourceObject = $this->toResourceObject($resource, [], null, 'https://api.test');

        self::assertSame(
            [
                'type' => 'book',
                'id' => '7',
                'links' => ['self' => 'https://api.test/books/7'],
            ],
            $resourceObject,
        );
    }

    #[Test]
    #[Group('spec:document-resource-object-links')]
    public function aResourceWithAnEmptyIdEmitsNoConventionSelfLink(): void
    {
        // A not-yet-persisted resource has no id, so no self URL is emitted.
        $resource = new StubResource('user', '');

        $resourceObject = $this->toResourceObject($resource, [], null, 'https://api.test');

        self::assertSame(
            [
                'type' => 'user',
                'id' => '',
            ],
            $resourceObject,
        );
    }

    #[Test]
    #[Group('spec:document-resource-object-links')]
    public function aResourceCanOptOutOfTheConventionSelfLink(): void
    {
        $resource = new StubSelfLinkResource(type: 'user', id: '1', emitsSelfLink: false);

        $resourceObject = $this->toResourceObject($resource, [], null, 'https://api.test');

        self::assertSame(
            [
                'type' => 'user',
                'id' => '1',
            ],
            $resourceObject,
        );
    }

    #[Test]
    #[Group('spec:document-resource-object-links')]
    public function aHandWrittenSelfLinkWinsOverTheConvention(): void
    {
        $resource = new StubSelfLinkResource(
            type: 'user',
            id: '1',
            links: ResourceLinks::withBaseUri('https://api.test', self: new Link('/me')),
        );

        $resourceObject = $this->toResourceObject($resource, [], null, 'https://api.test');

        self::assertSame(
            [
                'type' => 'user',
                'id' => '1',
                'links' => ['self' => 'https://api.test/me'],
            ],
            $resourceObject,
        );
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function transformToResourceObjectWithDefaultRelationship(): void
    {
        $resource = $this->createResource(
            'user',
            '1',
            [],
            null,
            [],
            ['father'],
            [
                'father' => static fn(array $object, JsonApiRequestInterface $request): ToOneRelationship => ToOneRelationship::create()
                    ->setData([''], new StubResource('user', '2')),
            ],
        );

        $resourceObject = $this->toResourceObject($resource, []);

        self::assertEquals(
            [
                'type' => 'user',
                'id' => '1',
                'links' => ['self' => '/user/1'],
                'relationships' => [
                    'father' => [
                        'data' => [
                            'type' => 'user',
                            'id' => '2',
                        ],
                    ],
                ],
            ],
            $resourceObject,
        );
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function transformToResourceObjectWithoutRelationships(): void
    {
        $resource = $this->createResource(
            'user',
            '1',
            [],
            null,
            [],
            [],
            [
                'father' => static fn(): ToOneRelationship => ToOneRelationship::create(),
            ],
        );

        $resourceObject = $this->toResourceObject($resource, [], StubJsonApiRequest::create(['fields' => ['user' => '']]));

        self::assertEquals(
            [
                'type' => 'user',
                'id' => '1',
                'links' => ['self' => '/user/1'],
            ],
            $resourceObject,
        );
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function transformToResourceObjectWithInvalidRelationship(): void
    {
        $resource = $this->createResource(
            'user',
            '1',
            [],
            null,
            [],
            ['father'],
            [
                'father' => static fn(): ToOneRelationship => ToOneRelationship::create(),
            ],
        );

        $this->expectException(InclusionUnrecognized::class);

        $this->toResourceObject($resource, [], StubJsonApiRequest::create(['include' => 'mother']));
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function transformToResourceObjectWithRelationships(): void
    {
        $resource = $this->createResource(
            'user',
            '1',
            [],
            null,
            [],
            [],
            [
                'father' => static fn(): ToOneRelationship => ToOneRelationship::create()
                    ->setData(null, new StubResource()),
            ],
        );

        $resourceObject = $this->toResourceObject($resource, []);

        self::assertEquals(
            [
                'type' => 'user',
                'id' => '1',
                'links' => ['self' => '/user/1'],
                'relationships' => [
                    'father' => [
                        'data' => null,
                    ],
                ],
            ],
            $resourceObject,
        );
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function transformToRelationshipObjectWhenEmpty(): void
    {
        $resource = $this->createResource('user', '1', [], null, [], [], []);

        $this->expectException(RelationshipNotExists::class);

        $this->toRelationshipObject($resource, [], null, 'father');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function transformToRelationshipObjectWhenNotFound(): void
    {
        $resource = $this->createResource(
            'user',
            '1',
            [],
            null,
            [],
            [],
            [
                'father' => static fn(): ToOneRelationship => ToOneRelationship::create()
                    ->setData(['Father Vader'], new StubResource('user', '2')),
            ],
        );

        $this->expectException(RelationshipNotExists::class);

        $this->toRelationshipObject($resource, [], null, 'mother');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function transformToRelationshipObject(): void
    {
        $resource = $this->createResource(
            'user',
            '1',
            [],
            null,
            [],
            [],
            [
                'father' => static fn(): ToOneRelationship => ToOneRelationship::create()
                    ->setData(['Father Vader'], new StubResource('user', '2')),
            ],
        );

        $resourceObject = $this->toRelationshipObject($resource, [], null, 'father');

        self::assertEquals(
            [
                'data' => [
                    'type' => 'user',
                    'id' => '2',
                ],
            ],
            $resourceObject,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function toResourceIdentifier(
        SerializerInterface $resource,
        mixed $object,
        ?JsonApiRequestInterface $request = null,
    ): ?array {
        $transformation = new ResourceTransformation(
            $resource,
            $object,
            '',
            $request ?? new StubJsonApiRequest(),
            '',
            '',
            '',
        );

        $transformer = new ResourceTransformer();

        return $transformer->transformToResourceIdentifier($transformation);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function toResourceObject(
        SerializerInterface $resource,
        mixed $object,
        ?JsonApiRequestInterface $request = null,
        string $baseUri = '',
    ): ?array {
        $transformation = new ResourceTransformation(
            $resource,
            $object,
            '',
            $request ?? new StubJsonApiRequest(),
            '',
            '',
            '',
            $baseUri,
        );

        $transformer = new ResourceTransformer();

        return $transformer->transformToResourceObject($transformation, new DummyData());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function toRelationshipObject(
        SerializerInterface $resource,
        mixed $object,
        ?JsonApiRequestInterface $request = null,
        string $requestedRelationshipName = '',
    ): ?array {
        $transformation = new ResourceTransformation(
            $resource,
            $object,
            '',
            $request ?? new StubJsonApiRequest(),
            '',
            $requestedRelationshipName,
            $requestedRelationshipName,
        );

        $transformer = new ResourceTransformer();

        return $transformer->transformToRelationshipObject($transformation, new DummyData());
    }

    /**
     * @param array<string, mixed>    $meta
     * @param array<string, callable> $attributes
     * @param list<string>            $defaultRelationships
     * @param array<string, callable> $relationships
     */
    private function createResource(
        string $type = '',
        string $id = '',
        array $meta = [],
        ?ResourceLinks $links = null,
        array $attributes = [],
        array $defaultRelationships = [],
        array $relationships = [],
    ): StubResource {
        return new StubResource($type, $id, $meta, $links, $attributes, $defaultRelationships, $relationships);
    }
}
