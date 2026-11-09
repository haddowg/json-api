<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\ExampleDocumentBuilder;
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
 * A relation read-only on create is left out of every create body, as a read-only
 * attribute is, because the runtime ignores it there.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(ExampleDocumentBuilder::class)]
#[CoversClass(RelationFacts::class)]
final class ReadOnlyRelationProjectionTest extends TestCase
{
    #[Test]
    public function aCreateListsOnlyTheRelationsItMaySet(): void
    {
        $schemas = $this->arrAt($this->document(), 'components', 'schemas');

        // A predicate-gated relation stays, as a predicate-gated attribute does.
        $settable = ['author', 'editor', 'reviewer'];
        self::assertSame($settable, \array_keys($this->arrAt($schemas, 'ArticlesCreateRequest', 'properties', 'data', 'properties', 'relationships', 'properties')));
        self::assertSame($settable, \array_keys($this->arrAt($schemas, 'ArticlesAtomicAdd', 'properties', 'relationships', 'properties')));
        self::assertSame(['author', 'publisher', 'reviewer'], \array_keys($this->arrAt($schemas, 'ArticlesUpdateRequest', 'properties', 'data', 'properties', 'relationships', 'properties')));
    }

    #[Test]
    public function aCreateExampleSetsNoReadOnlyRelation(): void
    {
        $example = $this->arrAt($this->document(), 'paths', '/articles', 'post', 'requestBody', 'content', 'application/vnd.api+json', 'example');

        self::assertSame(['author', 'editor', 'reviewer'], \array_keys($this->arrAt($example, 'data', 'relationships')));
    }

    #[Test]
    public function aCreateWithNothingItMaySetHasNoRelationshipsMember(): void
    {
        $articles = FakeTypeMetadata::resource('articles', [
            Id::make()->example('art-1')->build(),
            BelongsTo::make('author', 'people')->readOnly()->build(),
        ], relations: [new FakeRelationMetadata('author', ['people'], false, allowsReplace: false)]);

        $schemas = $this->arrAt($this->project([$articles, $this->people()]), 'components', 'schemas');

        self::assertArrayNotHasKey('relationships', $this->arrAt($schemas, 'ArticlesCreateRequest', 'properties', 'data', 'properties'));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(): array
    {
        $articles = FakeTypeMetadata::resource('articles', [
            Id::make()->example('art-1')->build(),
            Str::make('title')->example('Hello')->build(),
            BelongsTo::make('author', 'people')->build(),
            BelongsTo::make('editor', 'people')->readOnlyOnUpdate()->build(),
            BelongsTo::make('publisher', 'people')->readOnlyOnCreate()->build(),
            BelongsTo::make('owner', 'people')->readOnly()->build(),
            HasMany::make('reviewer', 'people')->readOnly(static fn(): bool => true)->build(),
        ], relations: [
            FakeRelationMetadata::toOne('author', ['people']),
            new FakeRelationMetadata('editor', ['people'], false, allowsReplace: false),
            FakeRelationMetadata::toOne('publisher', ['people']),
            new FakeRelationMetadata('owner', ['people'], false, allowsReplace: false),
            FakeRelationMetadata::toMany('reviewer', ['people']),
        ]);

        return $this->project([$articles, $this->people()]);
    }

    private function people(): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource('people', [Id::make()->example('per-1')->build()]);
    }

    /**
     * @param list<FakeTypeMetadata> $types
     *
     * @return array<array-key, mixed>
     */
    private function project(array $types): array
    {
        return (new OpenApiProjector())->project(new FakeServerMetadata(
            'API',
            '1.0.0',
            $types,
            servers: [new Server('https://api.example.com')],
            atomicOperations: new FakeAtomicOperationsMetadata('/operations'),
            generatesExamples: true,
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
