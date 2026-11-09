<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\ExampleDocumentBuilder;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\RelationFacts;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasManyBuilder;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A to-many relation's `minItems()` / `maxItems()` bound the linkage array of every write
 * that sets the whole membership, and none that carries a delta.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(OperationProjector::class)]
#[CoversClass(ExampleDocumentBuilder::class)]
#[CoversClass(RelationFacts::class)]
final class RelationItemBoundsProjectionTest extends TestCase
{
    #[Test]
    public function aResourceWriteBoundsTheLinkageForItsContext(): void
    {
        $schemas = $this->arrAt($this->document(), 'components', 'schemas');

        $create = $this->arrAt($schemas, 'ArticlesCreateRequest', 'properties', 'data', 'properties', 'relationships', 'properties');
        self::assertSame([
            ['$ref' => '#/components/schemas/ArticlesTagsRelationship'],
            ['properties' => ['data' => ['minItems' => 1, 'maxItems' => 5]]],
        ], $this->arrAt($create, 'tags', 'allOf'));
        self::assertSame(['properties' => ['data' => ['minItems' => 2]]], $this->arrAt($create, 'editors', 'allOf', '1'));
        self::assertSame(['$ref' => '#/components/schemas/ArticlesWatchersRelationship'], $this->arrAt($create, 'watchers'));

        $update = $this->arrAt($schemas, 'ArticlesUpdateRequest', 'properties', 'data', 'properties', 'relationships', 'properties');
        self::assertSame(['properties' => ['data' => ['minItems' => 1, 'maxItems' => 5]]], $this->arrAt($update, 'tags', 'allOf', '1'));
        self::assertSame(['$ref' => '#/components/schemas/ArticlesEditorsRelationship'], $this->arrAt($update, 'editors'));
        self::assertSame(['properties' => ['data' => ['maxItems' => 3]]], $this->arrAt($update, 'watchers', 'allOf', '1'));

        // The shared relationship object is the read shape too, so it stays unbounded.
        self::assertArrayNotHasKey('minItems', $this->arrAt($schemas, 'ArticlesTagsRelationship', 'properties', 'data'));
    }

    #[Test]
    public function anAtomicWriteBoundsItsInlineLinkage(): void
    {
        $schemas = $this->arrAt($this->document(), 'components', 'schemas');

        $add = $this->arrAt($schemas, 'ArticlesAtomicAdd', 'properties', 'relationships', 'properties', 'tags', 'properties', 'data');
        self::assertSame([1, 5], [$add['minItems'] ?? null, $add['maxItems'] ?? null]);
        $update = $this->arrAt($schemas, 'ArticlesAtomicUpdate', 'properties', 'relationships', 'properties', 'watchers', 'properties', 'data');
        self::assertSame(3, $update['maxItems'] ?? null);
    }

    #[Test]
    public function onlyAFullReplacementOfTheRelationshipIsBounded(): void
    {
        $path = $this->arrAt($this->document(), 'paths', '/articles/{id}/relationships/tags');

        self::assertSame([
            ['$ref' => '#/components/schemas/ArticlesTagsRelationshipDocument'],
            ['properties' => ['data' => ['minItems' => 1, 'maxItems' => 5]]],
        ], $this->arrAt($path, 'patch', 'requestBody', 'content', 'application/vnd.api+json', 'schema', 'allOf'));
        foreach (['post', 'delete'] as $method) {
            self::assertSame(
                ['$ref' => '#/components/schemas/ArticlesTagsRelationshipDocument'],
                $this->arrAt($path, $method, 'requestBody', 'content', 'application/vnd.api+json', 'schema'),
            );
        }
    }

    #[Test]
    public function anExampleTooShortForTheBoundIsNotShown(): void
    {
        $paths = $this->arrAt($this->document(), 'paths');

        $create = $this->arrAt($paths, '/articles', 'post', 'requestBody', 'content', 'application/vnd.api+json', 'example', 'data', 'relationships');
        self::assertSame(['tags', 'watchers'], \array_keys($create));
        self::assertArrayNotHasKey('example', $this->arrAt($paths, '/articles/{id}/relationships/reviewers', 'patch', 'requestBody', 'content', 'application/vnd.api+json'));
        // An addition carries a delta, which no bound applies to.
        self::assertArrayHasKey('example', $this->arrAt($paths, '/articles/{id}/relationships/reviewers', 'post', 'requestBody', 'content', 'application/vnd.api+json'));
        self::assertArrayHasKey('example', $this->arrAt($paths, '/articles/{id}/relationships/editors', 'patch', 'requestBody', 'content', 'application/vnd.api+json'));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(): array
    {
        $articles = FakeTypeMetadata::resource('articles', [
            Id::make()->example('art-1')->build(),
            HasMany::make('tags', 'tags')->minItems(1)->maxItems(5)->build(),
            HasMany::make('editors', 'tags')->onCreate(static function (HasManyBuilder $b): void {
                $b->minItems(2);
            })->build(),
            HasMany::make('watchers', 'tags')->onUpdate(static function (HasManyBuilder $b): void {
                $b->maxItems(3);
            })->build(),
            HasMany::make('reviewers', 'tags')->minItems(2)->build(),
        ], relations: [
            FakeRelationMetadata::toMany('tags', ['tags']),
            FakeRelationMetadata::toMany('editors', ['tags']),
            FakeRelationMetadata::toMany('watchers', ['tags']),
            FakeRelationMetadata::toMany('reviewers', ['tags']),
        ]);

        return (new OpenApiProjector())->project(new FakeServerMetadata(
            'API',
            '1.0.0',
            [$articles, FakeTypeMetadata::resource('tags', [Id::make()->example('tag-1')->build()])],
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
