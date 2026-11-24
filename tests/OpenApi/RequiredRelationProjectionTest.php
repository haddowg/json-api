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

        self::assertSame(['type', 'attributes', 'relationships'], $data['required'] ?? null);
        self::assertSame(['author', 'editor', 'sponsor', 'tags', 'readers'], $this->arrAt($data, 'properties', 'relationships')['required'] ?? null);
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

        self::assertSame(['type', 'attributes', 'relationships'], $add['required'] ?? null);
        self::assertSame(['author', 'editor', 'sponsor', 'tags', 'readers'], $this->arrAt($add, 'properties', 'relationships')['required'] ?? null);
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
    public function aRequiredToOneRefusesNullWhereItIsRequired(): void
    {
        $schemas = $this->schemas();
        $create = $this->arrAt($schemas, 'ArticlesCreateRequest', 'properties', 'data', 'properties', 'relationships', 'properties');
        $update = $this->arrAt($schemas, 'ArticlesUpdateRequest', 'properties', 'data', 'properties', 'relationships', 'properties');
        $refusesNull = ['properties' => ['data' => ['type' => 'object']]];

        self::assertSame([['$ref' => '#/components/schemas/ArticlesAuthorRelationship'], $refusesNull], $this->arrAt($create, 'author', 'allOf'));
        self::assertSame($refusesNull, $this->arrAt($create, 'editor', 'allOf', '1'));
        self::assertSame($refusesNull, $this->arrAt($update, 'author', 'allOf', '1'));
        // Required on create only, or nullable as well: the shared shape, which takes null.
        self::assertSame(['$ref' => '#/components/schemas/ArticlesEditorRelationship'], $this->arrAt($update, 'editor'));
        self::assertSame(['$ref' => '#/components/schemas/ArticlesSponsorRelationship'], $this->arrAt($create, 'sponsor'));
    }

    #[Test]
    public function theAtomicWritesAndTheRelationshipEndpointRefuseANullRequiredToOne(): void
    {
        $document = $this->document();
        $schemas = $this->arrAt($document, 'components', 'schemas');

        $add = $this->arrAt($schemas, 'ArticlesAtomicAdd', 'properties', 'relationships', 'properties');
        self::assertArrayNotHasKey('anyOf', $this->arrAt($add, 'author', 'properties', 'data'));
        self::assertArrayHasKey('anyOf', $this->arrAt($add, 'sponsor', 'properties', 'data'));
        $update = $this->arrAt($schemas, 'ArticlesAtomicUpdate', 'properties', 'relationships', 'properties');
        self::assertArrayNotHasKey('anyOf', $this->arrAt($update, 'author', 'properties', 'data'));
        self::assertArrayHasKey('anyOf', $this->arrAt($update, 'editor', 'properties', 'data'));

        $body = $this->arrAt($document, 'paths', '/articles/{id}/relationships/author', 'patch', 'requestBody', 'content', 'application/vnd.api+json', 'schema');
        self::assertSame(['properties' => ['data' => ['type' => 'object']]], $this->arrAt($body, 'allOf', '1'));
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
        return $this->arrAt($this->document(), 'components', 'schemas');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(): array
    {
        $articles = FakeTypeMetadata::resource('articles', [
            Id::make()->example('art-1')->build(),
            Str::make('title')->required()->build(),
            BelongsTo::make('author', 'tags')->required()->build(),
            BelongsTo::make('editor', 'tags')->requiredOnCreate()->build(),
            BelongsTo::make('sponsor', 'tags')->required()->nullable()->build(),
            BelongsTo::make('approver', 'tags')->readOnlyOnCreate()->required()->build(),
            HasMany::make('tags', 'tags')->requiredOnCreate()->build(),
            HasMany::make('readers', 'tags')->required()->build(),
            HasMany::make('labels', 'tags')->build(),
        ], relations: [
            FakeRelationMetadata::toOne('author', ['tags']),
            FakeRelationMetadata::toOne('editor', ['tags']),
            FakeRelationMetadata::toOne('sponsor', ['tags']),
            FakeRelationMetadata::toOne('approver', ['tags']),
            FakeRelationMetadata::toMany('tags', ['tags']),
            FakeRelationMetadata::toMany('readers', ['tags']),
            FakeRelationMetadata::toMany('labels', ['tags']),
        ]);

        return (new OpenApiProjector())->project(new FakeServerMetadata(
            'API',
            '1.0.0',
            [$articles, FakeTypeMetadata::resource('tags', [Id::make()->example('tag-1')->build(), Str::make('name')->build()])],
            servers: [new Server('https://api.example.com')],
            atomicOperations: new FakeAtomicOperationsMetadata('/operations'),
        ))->toArray();
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
