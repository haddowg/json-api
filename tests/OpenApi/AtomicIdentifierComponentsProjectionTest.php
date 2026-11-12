<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The atomic relationship operations reference one `AtomicIdentifier` union and one
 * `AtomicIdentifiers` array, so a type joining the server changes those components and
 * leaves the operations alone.
 */
#[CoversClass(OpenApiProjector::class)]
final class AtomicIdentifierComponentsProjectionTest extends TestCase
{
    private const REF = '#/components/schemas/';

    #[Test]
    public function aMultiTypeServerReferencesOneDiscriminatedUnion(): void
    {
        $schemas = $this->schemas([
            self::type('articles', [FakeRelationMetadata::toOne('author', ['people']), FakeRelationMetadata::toMany('tags', ['tags'])]),
            self::type('people'),
            self::type('tags'),
        ]);

        self::assertSame([
            'oneOf' => [['$ref' => self::REF . 'PeopleAtomicIdentifier'], ['$ref' => self::REF . 'TagsAtomicIdentifier']],
            'discriminator' => [
                'propertyName' => 'type',
                'mapping' => ['people' => self::REF . 'PeopleAtomicIdentifier', 'tags' => self::REF . 'TagsAtomicIdentifier'],
            ],
        ], self::withoutDescription(self::at($schemas, 'AtomicIdentifier')));
        self::assertSame(['type' => 'array', 'items' => ['$ref' => self::REF . 'AtomicIdentifier']], self::at($schemas, 'AtomicIdentifiers'));
        self::assertOperationsReferenceTheComponents($schemas);
    }

    #[Test]
    public function aSingleTypeServerKeepsAOneBranchUnion(): void
    {
        $schemas = $this->schemas([
            self::type('articles', [FakeRelationMetadata::toMany('tags', ['tags'])]),
            self::type('tags'),
        ]);

        self::assertSame([
            'oneOf' => [['$ref' => self::REF . 'TagsAtomicIdentifier']],
            'discriminator' => ['propertyName' => 'type', 'mapping' => ['tags' => self::REF . 'TagsAtomicIdentifier']],
        ], self::withoutDescription(self::at($schemas, 'AtomicIdentifier')));
        self::assertSame(['type' => 'array', 'items' => ['$ref' => self::REF . 'AtomicIdentifier']], self::at($schemas, 'AtomicIdentifiers'));
        self::assertOperationsReferenceTheComponents($schemas);
    }

    #[Test]
    public function aTypeJoiningTheServerLeavesTheOperationsUnchanged(): void
    {
        $before = $this->schemas([
            self::type('articles', [FakeRelationMetadata::toMany('tags', ['tags'])]),
            self::type('tags'),
        ]);
        $after = $this->schemas([
            self::type('articles', [FakeRelationMetadata::toMany('tags', ['tags']), FakeRelationMetadata::toOne('author', ['people'])]),
            self::type('tags'),
            self::type('people'),
        ]);

        foreach (['AtomicRelationshipAddOperation', 'AtomicRelationshipUpdateOperation', 'AtomicRelationshipRemoveOperation', 'AtomicIdentifiers'] as $component) {
            self::assertSame(self::at($before, $component), self::at($after, $component), $component);
        }
        self::assertSame(
            [['$ref' => self::REF . 'TagsAtomicIdentifier'], ['$ref' => self::REF . 'PeopleAtomicIdentifier']],
            self::at($after, 'AtomicIdentifier', 'oneOf'),
        );
    }

    #[Test]
    public function aServerWithNoRelationsHasNoIdentifierComponents(): void
    {
        $schemas = $this->schemas([self::type('articles')]);

        foreach (['AtomicIdentifier', 'AtomicIdentifiers', 'AtomicRelationshipAddOperation', 'AtomicRelationshipUpdateOperation', 'AtomicRelationshipRemoveOperation'] as $component) {
            self::assertArrayNotHasKey($component, $schemas);
        }
    }

    #[Test]
    public function typesNamedLikeTheComponentsDoNotCollideWithThem(): void
    {
        $schemas = $this->schemas([
            self::type('articles', [FakeRelationMetadata::toMany('ids', ['atomic-identifier']), FakeRelationMetadata::toOne('owner', ['atomic'])]),
            self::type('atomic-identifier'),
            self::type('atomic'),
        ]);

        self::assertSame(
            ['atomic-identifier' => self::REF . 'AtomicIdentifierAtomicIdentifier', 'atomic' => self::REF . 'AtomicAtomicIdentifier'],
            self::at($schemas, 'AtomicIdentifier', 'discriminator', 'mapping'),
        );
        self::assertArrayHasKey('AtomicIdentifierResource', $schemas);
        self::assertSame(['type' => 'array', 'items' => ['$ref' => self::REF . 'AtomicIdentifier']], self::at($schemas, 'AtomicIdentifiers'));
    }

    #[Test]
    public function aNamedShapeTakingTheNameFails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Field "value" names the schema component "AtomicIdentifiers", which the document already generates.');

        $this->schemas([
            FakeTypeMetadata::resource(
                type: 'articles',
                fields: [Id::make()->build(), Union::make('value')->of(Str::make(), Integer::make())->component('AtomicIdentifiers')->build()],
                relations: [FakeRelationMetadata::toMany('tags', ['tags'])],
            ),
            self::type('tags'),
        ]);
    }

    /**
     * @param array<array-key, mixed> $schemas
     */
    private static function assertOperationsReferenceTheComponents(array $schemas): void
    {
        self::assertSame(['$ref' => self::REF . 'AtomicIdentifiers'], self::at($schemas, 'AtomicRelationshipAddOperation', 'properties', 'data'));
        self::assertSame(['$ref' => self::REF . 'AtomicIdentifiers'], self::at($schemas, 'AtomicRelationshipRemoveOperation', 'properties', 'data'));
        self::assertSame(
            ['oneOf' => [['$ref' => self::REF . 'AtomicIdentifier'], ['type' => 'null'], ['$ref' => self::REF . 'AtomicIdentifiers']]],
            self::at($schemas, 'AtomicRelationshipUpdateOperation', 'properties', 'data'),
        );
    }

    /**
     * @param array<array-key, mixed> $schemas
     */
    private static function at(array $schemas, string ...$keys): mixed
    {
        $value = $schemas;
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function withoutDescription(mixed $schema): array
    {
        self::assertIsArray($schema);
        unset($schema['description']);

        return $schema;
    }

    /**
     * @param list<FakeRelationMetadata> $relations
     */
    private static function type(string $type, array $relations = []): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: $type,
            fields: [Id::make()->build(), Str::make('title')->build()],
            relations: $relations,
        );
    }

    /**
     * @param list<FakeTypeMetadata> $types
     *
     * @return array<array-key, mixed>
     */
    private function schemas(array $types): array
    {
        $document = (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: $types,
            atomicOperations: new FakeAtomicOperationsMetadata(),
        ))->toArray();

        $schemas = self::at($document, 'components', 'schemas');
        self::assertIsArray($schemas);

        return $schemas;
    }
}
