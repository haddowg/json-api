<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresIdentifierMeta;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\RelationFacts;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\WriteOnlyRelationMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relation declaring the members its identifier meta resolver adds
 * ({@see DeclaresIdentifierMeta}) types them on each response linkage identifier's `meta`,
 * through a `<Type><Rel>IdentifierMeta` component, beside any pivot.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(RelationFacts::class)]
#[CoversClass(GeneratorContract::class)]
final class IdentifierMetaProjectionTest extends TestCase
{
    #[Test]
    public function aMonomorphicLinkageIdentifierTypesItsDeclaredMeta(): void
    {
        $schemas = $this->arrAt($this->document(new FakeRelationMetadata('author', ['people'], false, identifierMetaFields: [
            Str::make('role')->guaranteed()->build(),
            Boolean::make('primary')->build(),
            Str::make('secret')->hidden()->build(),
        ])), 'components', 'schemas');

        $expected = ['allOf' => [
            ['$ref' => '#/components/schemas/PeopleResourceIdentifier'],
            ['type' => 'object', 'properties' => ['meta' => ['$ref' => '#/components/schemas/ArticlesAuthorIdentifierMeta']]],
        ]];
        self::assertSame($expected, $this->arrAt($schemas, 'ArticlesAuthorRelationship', 'properties', 'data', 'anyOf', '0'));
        self::assertSame($expected, $this->arrAt($schemas, 'ArticlesAuthorRelationshipDocument', 'properties', 'data', 'anyOf', '0'));

        // The read rules a pivot follows: presence promised, `null` unless guaranteed.
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'role' => ['type' => 'string'],
                'primary' => ['type' => ['boolean', 'null']],
            ],
            'required' => ['role', 'primary'],
        ], $this->arrAt($schemas, 'ArticlesAuthorIdentifierMeta'));
    }

    #[Test]
    public function aPivotAndDeclaredMetaAreSiblingsAndOnlyTheReadShapeCarriesTheDeclaredMeta(): void
    {
        $schemas = $this->arrAt($this->document(new FakeRelationMetadata(
            'tags',
            ['people'],
            true,
            pivotFields: [Integer::make('position')->build()],
            identifierMetaFields: [Str::make('addedVia')->build()],
        )), 'components', 'schemas');

        self::assertSame(
            ['allOf' => [
                ['$ref' => '#/components/schemas/ArticlesTagsIdentifierMeta'],
                ['type' => 'object', 'properties' => ['pivot' => ['$ref' => '#/components/schemas/ArticlesTagsPivot']]],
            ]],
            $this->arrAt($schemas, 'ArticlesTagsRelationship', 'properties', 'data', 'items', 'allOf', '1', 'properties', 'meta'),
        );

        // A write never sends the read-only identifier meta: only the request pivot.
        foreach (['ArticlesTagsWriteRelationship', 'ArticlesTagsRelationshipRequest'] as $component) {
            self::assertSame(
                ['type' => 'object', 'properties' => ['pivot' => ['$ref' => '#/components/schemas/ArticlesTagsWritePivot']]],
                $this->arrAt($schemas, $component, 'properties', 'data', 'items', 'allOf', '1', 'properties', 'meta'),
                $component,
            );
        }
    }

    #[Test]
    public function aPolymorphicLinkageNarrowsItsWholeUnion(): void
    {
        $schemas = $this->arrAt($this->document(new FakeRelationMetadata('subjects', ['people', 'robots'], true, polymorphic: true, identifierMetaFields: [
            Integer::make('order')->build(),
        ])), 'components', 'schemas');

        $items = $this->arrAt($schemas, 'ArticlesSubjectsRelationship', 'properties', 'data', 'items');
        self::assertSame(
            ['people' => '#/components/schemas/PeopleResourceIdentifier', 'robots' => '#/components/schemas/RobotsResourceIdentifier'],
            $this->arrAt($items, 'allOf', '0', 'discriminator', 'mapping'),
        );
        self::assertSame(
            ['type' => 'object', 'properties' => ['meta' => ['$ref' => '#/components/schemas/ArticlesSubjectsIdentifierMeta']]],
            $this->arrAt($items, 'allOf', '1'),
        );
    }

    #[Test]
    public function theReadLinkageExampleCarriesTheDeclaredMeta(): void
    {
        $paths = $this->arrAt($this->document(new FakeRelationMetadata('author', ['people'], false, identifierMetaFields: [
            Str::make('role')->example('lead')->build(),
        ])), 'paths');

        self::assertSame(
            ['type' => 'people', 'id' => 'per-1', 'meta' => ['role' => 'lead']],
            $this->arrAt($paths, '/articles/{id}/relationships/author', 'get', 'responses', '200', 'content', 'application/vnd.api+json', 'example', 'data'),
        );
        self::assertSame(
            ['type' => 'people', 'id' => 'per-1'],
            $this->arrAt($paths, '/articles/{id}/relationships/author', 'patch', 'requestBody', 'content', 'application/vnd.api+json', 'example', 'data'),
        );
    }

    #[Test]
    public function onlyADocumentDeclaringIdentifierMetaCarriesItsContract(): void
    {
        self::assertSame(GeneratorContract::IDENTIFIER_META, $this->contract(new FakeRelationMetadata('author', ['people'], false, identifierMetaFields: [Str::make('role')->build()])));
        self::assertSame(GeneratorContract::BASELINE, $this->contract(new FakeRelationMetadata('author', ['people'], false)));
    }

    #[Test]
    public function metadataThatDoesNotDeclareIdentifierMetaProjectsAsBefore(): void
    {
        $plain = $this->document(FakeRelationMetadata::toOne('author', ['people']));

        // A wrapper that does not implement the interface hides the declaration.
        self::assertSame($plain, $this->document(new WriteOnlyRelationMetadata(
            new FakeRelationMetadata('author', ['people'], false, identifierMetaFields: [Str::make('role')->build()]),
            writeOnly: false,
        )));
        self::assertArrayNotHasKey('ArticlesAuthorIdentifierMeta', $this->arrAt($plain, 'components', 'schemas'));
    }

    private function contract(RelationMetadataInterface $relation): int
    {
        $generator = $this->arrAt($this->document($relation), 'info', 'x-generator');
        self::assertIsInt($generator['contract']);

        return $generator['contract'];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(RelationMetadataInterface $relation): array
    {
        $person = static fn(string $type, string $id): FakeTypeMetadata => FakeTypeMetadata::resource(
            type: $type,
            fields: [Id::make()->example($id)->build(), Str::make('name')->example('Ada')->build()],
        );

        return (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(type: 'articles', fields: [Id::make()->example('art-1')->build(), Str::make('title')->example('Hello')->build()], relations: [$relation]),
                $person('people', 'per-1'),
                $person('robots', 'bot-1'),
            ],
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
            self::assertArrayHasKey($key, $value, \implode('.', $keys));
            $value = $value[$key];
        }
        self::assertIsArray($value);

        return $value;
    }
}
