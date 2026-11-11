<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\RelationFacts;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(RelationFacts::class)]
final class RelationshipExampleProjectionTest extends TestCase
{
    #[Test]
    public function aToOneShowsOneIdentifierOfTheRelatedType(): void
    {
        $component = $this->relationship(FakeRelationMetadata::toOne('author', ['people']), 'ArticlesAuthorRelationship');

        self::assertSame([['data' => ['type' => 'people', 'id' => 'per-1']]], $component['examples'] ?? null);
    }

    #[Test]
    public function aToManyShowsAListOfOne(): void
    {
        $component = $this->relationship(FakeRelationMetadata::toMany('authors', ['people']), 'ArticlesAuthorsRelationship');

        self::assertSame([['data' => [['type' => 'people', 'id' => 'per-1']]]], $component['examples'] ?? null);
    }

    #[Test]
    public function theRelatedTypesIdExampleWinsAndExampleIdFillsInWhereItHasNone(): void
    {
        $declared = new FakeRelationMetadata('author', ['people'], false, exampleId: 'declared');
        self::assertSame([['data' => ['type' => 'people', 'id' => 'per-1']]], $this->relationship($declared, 'ArticlesAuthorRelationship')['examples'] ?? null);

        $fallback = new FakeRelationMetadata('editor', ['editors'], false, exampleId: 'ed-9');
        self::assertSame([['data' => ['type' => 'editors', 'id' => 'ed-9']]], $this->relationship($fallback, 'ArticlesEditorRelationship')['examples'] ?? null);
    }

    #[Test]
    public function aPolymorphicRelationShowsItsFirstType(): void
    {
        $component = $this->relationship(FakeRelationMetadata::morph('subject', ['people', 'editors']), 'ArticlesSubjectRelationship');

        self::assertSame([['data' => ['type' => 'people', 'id' => 'per-1']]], $component['examples'] ?? null);
    }

    #[Test]
    public function noIdLeavesTheRelationshipWithoutAnExample(): void
    {
        self::assertArrayNotHasKey('examples', $this->relationship(FakeRelationMetadata::toOne('editor', ['editors']), 'ArticlesEditorRelationship'));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function relationship(FakeRelationMetadata $relation, string $component): array
    {
        $document = (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource('articles', [Id::make()->example('art-1')->build()], relations: [$relation]),
                FakeTypeMetadata::resource('people', [Id::make()->example('per-1')->build()]),
                FakeTypeMetadata::resource('editors', [Id::make()->build()]),
            ],
        ))->toArray();

        $components = $document['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);
        $schema = $schemas[$component] ?? null;
        self::assertIsArray($schema);

        return $schema;
    }
}
