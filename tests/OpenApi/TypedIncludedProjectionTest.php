<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\IncludeReachability;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A compound document's `included` is a `oneOf` over the resources of the types its
 * `?include` paths reach, discriminated by `type`, and a document whose paths reach
 * nothing has no `included`.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(IncludeReachability::class)]
#[CoversClass(GeneratorContract::class)]
final class TypedIncludedProjectionTest extends TestCase
{
    #[Test]
    public function includedIsAUnionOfEveryTypeTheIncludePathsReach(): void
    {
        $schemas = $this->arrAt($this->document(), 'components', 'schemas');

        $expected = [
            'type' => 'array',
            'items' => [
                'oneOf' => [
                    ['$ref' => '#/components/schemas/PeopleResource'],
                    ['$ref' => '#/components/schemas/CompaniesResource'],
                    ['$ref' => '#/components/schemas/ImagesResource'],
                    ['$ref' => '#/components/schemas/VideosResource'],
                ],
                'discriminator' => [
                    'propertyName' => 'type',
                    'mapping' => [
                        'people' => '#/components/schemas/PeopleResource',
                        'companies' => '#/components/schemas/CompaniesResource',
                        'images' => '#/components/schemas/ImagesResource',
                        'videos' => '#/components/schemas/VideosResource',
                    ],
                ],
            ],
        ];
        self::assertSame($expected, $this->arrAt($schemas, 'ArticlesDocument', 'properties', 'included'));
        self::assertSame($expected, $this->arrAt($schemas, 'ArticlesCollection', 'properties', 'included'));
    }

    #[Test]
    public function aRelatedDocumentsIncludedIsRootedAtTheRelatedType(): void
    {
        $schemas = $this->arrAt($this->document(), 'components', 'schemas');

        self::assertSame(
            [
                'oneOf' => [['$ref' => '#/components/schemas/CompaniesResource']],
                'discriminator' => ['propertyName' => 'type', 'mapping' => ['companies' => '#/components/schemas/CompaniesResource']],
            ],
            $this->arrAt($schemas, 'ArticlesAuthorRelatedDocument', 'properties', 'included', 'items'),
        );
    }

    #[Test]
    public function aDocumentWhosePathsReachNothingHasNoIncluded(): void
    {
        $schemas = $this->arrAt($this->document(), 'components', 'schemas');

        self::assertArrayNotHasKey('included', $this->arrAt($schemas, 'CompaniesDocument', 'properties'));
        self::assertArrayNotHasKey('included', $this->arrAt($schemas, 'CompaniesCollection', 'properties'));
        self::assertArrayNotHasKey('included', $this->arrAt($schemas, 'ArticlesAttachmentsRelatedCollection', 'properties'));
    }

    #[Test]
    public function aTypeTheServerDoesNotRegisterIsLeftOut(): void
    {
        $schemas = $this->arrAt($this->document(), 'components', 'schemas');

        // `people.ghost` reaches only `ghosts`, which has no resource component.
        self::assertArrayNotHasKey('included', $this->arrAt($schemas, 'PeopleDocument', 'properties'));
    }

    #[Test]
    public function aDocumentWithATypedIncludedCarriesTheDiscriminatedUnionsContract(): void
    {
        self::assertSame(
            ['contract' => GeneratorContract::DISCRIMINATED_UNIONS],
            $this->arrAt($this->document(), 'info', 'x-generator'),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(): array
    {
        return (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                self::type('articles', [
                    new FakeRelationMetadata('author', ['people'], false, relatedIncludablePaths: ['company']),
                    new FakeRelationMetadata('attachments', ['images', 'videos'], true),
                ], ['author', 'author.company', 'attachments']),
                self::type('people', [
                    FakeRelationMetadata::toOne('company', ['companies']),
                    new FakeRelationMetadata('ghost', ['ghosts'], false, relatedEndpoint: false),
                ], ['ghost']),
                self::type('companies'),
                self::type('images'),
                self::type('videos'),
            ],
        ))->toArray();
    }

    /**
     * @param list<FakeRelationMetadata> $relations
     * @param list<string>               $includablePaths
     */
    private static function type(string $type, array $relations = [], array $includablePaths = []): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: $type,
            fields: [Id::make()->build(), Str::make('name')->build()],
            relations: $relations,
            includablePaths: $includablePaths,
        );
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
