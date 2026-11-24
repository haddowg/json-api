<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\RelationFacts;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A create request lists the relationships a create must send, and the members it must
 * send them in, as it lists required attributes. A required to-many's linkage may not be
 * empty in a write where it is required.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(RelationFacts::class)]
final class RequiredRelationProjectionTest extends TestCase
{
    #[Test]
    public function aCreateRequestRequiresItsRequiredRelationships(): void
    {
        $data = $this->arrAt($this->schemas(), 'ArticlesCreateRequest', 'properties', 'data');

        self::assertSame(['type', 'relationships'], $data['required'] ?? null);
        self::assertSame(['author', 'editor', 'tags', 'readers'], $this->arrAt($data, 'properties', 'relationships')['required'] ?? null);
    }

    #[Test]
    public function anUpdateRequestRequiresNoRelationship(): void
    {
        $data = $this->arrAt($this->schemas(), 'ArticlesUpdateRequest', 'properties', 'data');

        self::assertSame(['type', 'id'], $data['required'] ?? null);
        self::assertArrayNotHasKey('required', $this->arrAt($data, 'properties', 'relationships'));
    }

    #[Test]
    public function anAtomicAddRequiresItsRequiredRelationships(): void
    {
        $add = $this->arrAt($this->schemas(), 'ArticlesAtomicAdd');

        self::assertSame(['type', 'relationships'], $add['required'] ?? null);
        self::assertSame(['author', 'editor', 'tags', 'readers'], $this->arrAt($add, 'properties', 'relationships')['required'] ?? null);
        self::assertSame(1, $this->arrAt($add, 'properties', 'relationships', 'properties', 'tags', 'properties', 'data')['minItems'] ?? null);
    }

    #[Test]
    public function aRequiredToManyIsNotEmptyWhereItIsRequired(): void
    {
        $schemas = $this->schemas();
        $create = $this->arrAt($schemas, 'ArticlesCreateRequest', 'properties', 'data', 'properties', 'relationships', 'properties');
        $update = $this->arrAt($schemas, 'ArticlesUpdateRequest', 'properties', 'data', 'properties', 'relationships', 'properties');

        self::assertSame(['properties' => ['data' => ['minItems' => 1]]], $this->arrAt($create, 'tags', 'allOf', '1'));
        self::assertSame(['properties' => ['data' => ['minItems' => 1]]], $this->arrAt($create, 'readers', 'allOf', '1'));
        self::assertSame(['properties' => ['data' => ['minItems' => 1]]], $this->arrAt($update, 'readers', 'allOf', '1'));
        self::assertSame(['$ref' => '#/components/schemas/ArticlesTagsRelationship'], $this->arrAt($update, 'tags'));
        self::assertSame(['$ref' => '#/components/schemas/ArticlesLabelsRelationship'], $this->arrAt($create, 'labels'));
    }

    #[Test]
    public function aTypeRequiringNothingKeepsItsCreateRequest(): void
    {
        $data = $this->arrAt($this->schemas(), 'TagsCreateRequest', 'properties', 'data');

        self::assertSame(['type'], $data['required'] ?? null);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function schemas(): array
    {
        $articles = FakeTypeMetadata::resource('articles', [
            Id::make()->example('art-1')->build(),
            Str::make('title')->required()->build(),
            BelongsTo::make('author', 'tags')->required()->build(),
            BelongsTo::make('editor', 'tags')->requiredOnCreate()->build(),
            BelongsTo::make('approver', 'tags')->readOnlyOnCreate()->required()->build(),
            HasMany::make('tags', 'tags')->requiredOnCreate()->build(),
            HasMany::make('readers', 'tags')->required()->build(),
            HasMany::make('labels', 'tags')->build(),
        ], relations: [
            FakeRelationMetadata::toOne('author', ['tags']),
            FakeRelationMetadata::toOne('editor', ['tags']),
            FakeRelationMetadata::toOne('approver', ['tags']),
            FakeRelationMetadata::toMany('tags', ['tags']),
            FakeRelationMetadata::toMany('readers', ['tags']),
            FakeRelationMetadata::toMany('labels', ['tags']),
        ]);

        $document = (new OpenApiProjector())->project(new FakeServerMetadata(
            'API',
            '1.0.0',
            [$articles, FakeTypeMetadata::resource('tags', [Id::make()->example('tag-1')->build(), Str::make('name')->build()])],
            servers: [new Server('https://api.example.com')],
            atomicOperations: new FakeAtomicOperationsMetadata('/operations'),
        ))->toArray();

        return $this->arrAt($document, 'components', 'schemas');
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function arrAt(array $data, string ...$keys): array
    {
        $value = $data;
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }
        self::assertIsArray($value);

        return $value;
    }
}
