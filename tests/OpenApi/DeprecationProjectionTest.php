<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionDeprecation;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresDeprecatedRelation;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresDeprecation;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortFamily;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\DeprecatedTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Deprecation markers in the projected document: whole types and named operations
 * through {@see DeclaresDeprecation}, actions through {@see DeclaresActionDeprecation},
 * relations through {@see DeclaresDeprecatedRelation}, and fields, filters and sorts
 * through their own `deprecated()`.
 */
#[CoversClass(OperationProjector::class)]
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(SchemaProjector::class)]
#[CoversClass(GeneratorContract::class)]
final class DeprecationProjectionTest extends TestCase
{
    private const array ARTICLE_OPERATIONS = [
        ['/articles', 'get'],
        ['/articles', 'post'],
        ['/articles/{id}', 'get'],
        ['/articles/{id}', 'patch'],
        ['/articles/{id}', 'delete'],
        ['/articles/{id}/author', 'get'],
        ['/articles/{id}/relationships/author', 'get'],
        ['/articles/{id}/relationships/author', 'patch'],
        ['/articles/{id}/-actions/publish', 'post'],
    ];

    #[Test]
    public function aDeprecatedTypeMarksEveryOneOfItsOperations(): void
    {
        $paths = $this->paths($this->server(new DeprecatedTypeMetadata($this->articles(), deprecated: true)));

        foreach (self::ARTICLE_OPERATIONS as [$path, $method]) {
            self::assertTrue($this->at($paths, $path, $method, 'deprecated'), \sprintf('%s %s', $method, $path));
        }
        foreach (['/people', '/people/{id}'] as $path) {
            foreach ($this->arrAt($paths, $path) as $member => $operation) {
                if (\is_array($operation)) {
                    self::assertArrayNotHasKey('deprecated', $operation, \sprintf('%s %s', $member, $path));
                }
            }
        }
    }

    #[Test]
    public function aSunsetAppendsItsDateToEveryOperationDescription(): void
    {
        $plain = $this->paths($this->server($this->articles()));
        $paths = $this->paths($this->server(new DeprecatedTypeMetadata(
            $this->articles(),
            deprecated: true,
            sunset: new \DateTimeImmutable('2027-06-30T00:00:00Z'),
        )));

        $sentence = 'This operation may stop responding on or after 2027-06-30, its sunset date.';
        foreach (self::ARTICLE_OPERATIONS as [$path, $method]) {
            $before = $this->at($plain, $path, $method, 'description');
            $expected = \is_string($before) ? $before . "\n\n" . $sentence : $sentence;
            self::assertSame($expected, $this->at($paths, $path, $method, 'description'), \sprintf('%s %s', $method, $path));
        }
    }

    #[Test]
    public function aSunsetAloneMarksNothingDeprecated(): void
    {
        $paths = $this->paths($this->server(new DeprecatedTypeMetadata(
            $this->articles(),
            sunset: new \DateTimeImmutable('2027-06-30T00:00:00Z'),
        )));

        self::assertArrayNotHasKey('deprecated', $this->arrAt($paths, '/articles', 'get'));
        $description = $this->at($paths, '/articles', 'get', 'description');
        self::assertIsString($description);
        self::assertStringEndsWith('its sunset date.', $description);
    }

    #[Test]
    public function aNamedOperationIsTheOnlyOneMarked(): void
    {
        $paths = $this->paths($this->server(new DeprecatedTypeMetadata(
            $this->articles(),
            operations: ['delete', 'fetchRelated.author', 'updateRelationship.author'],
        )));

        $marked = [
            ['/articles/{id}', 'delete'],
            ['/articles/{id}/author', 'get'],
            ['/articles/{id}/relationships/author', 'patch'],
        ];
        foreach (self::ARTICLE_OPERATIONS as $operation) {
            [$path, $method] = $operation;
            if (\in_array($operation, $marked, true)) {
                self::assertTrue($this->at($paths, $path, $method, 'deprecated'), \sprintf('%s %s', $method, $path));
            } else {
                self::assertArrayNotHasKey('deprecated', $this->arrAt($paths, $path, $method), \sprintf('%s %s', $method, $path));
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesTheTypeDoesNotExpose(): iterable
    {
        yield 'unknown relation' => ['fetchRelated.editor'];
        yield 'not an operation name' => ['fetchSomething'];
        yield 'an action' => ['action.publish.post'];
    }

    #[Test]
    #[DataProvider('namesTheTypeDoesNotExpose')]
    public function aNameTheTypeDoesNotExposeFailsTheProjection(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('declares the operation "%s" deprecated', $name));

        $this->paths($this->server(new DeprecatedTypeMetadata($this->articles(), operations: [$name])));
    }

    #[Test]
    public function aNameForAnOperationTheTypeDoesNotAllowFailsTheProjection(): void
    {
        $readOnly = FakeTypeMetadata::resource(type: 'articles', fields: [Id::make()->build()], operations: [\haddowg\JsonApi\OpenApi\Metadata\OperationType::FetchOne]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('declares the operation "delete" deprecated');

        $this->paths($this->server(new DeprecatedTypeMetadata($readOnly, operations: ['delete'])));
    }

    #[Test]
    public function aDeprecatedActionIsMarkedOnItsOwn(): void
    {
        $articles = FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make()->build()],
            actions: [
                new FakeActionMetadata('publish', deprecated: true),
                new FakeActionMetadata('archive'),
            ],
        );

        $paths = $this->paths(new FakeServerMetadata(title: 'Blog API', version: '1.0.0', types: [$articles]));

        self::assertTrue($this->at($paths, '/articles/{id}/-actions/publish', 'post', 'deprecated'));
        self::assertArrayNotHasKey('deprecated', $this->arrAt($paths, '/articles/{id}/-actions/archive', 'post'));
        self::assertArrayNotHasKey('deprecated', $this->arrAt($paths, '/articles/{id}', 'get'));
    }

    #[Test]
    public function nothingDeclaredRendersExactlyAsBefore(): void
    {
        $declaredNothing = new DeprecatedTypeMetadata($this->articles());

        self::assertSame(
            (new OpenApiProjector())->project($this->server($this->articles()))->toArray(),
            (new OpenApiProjector())->project($this->server($declaredNothing))->toArray(),
        );
    }

    #[Test]
    public function aDeprecatedAttributeIsMarkedInTheReadAndWriteSchemas(): void
    {
        $schemas = $this->schemas($this->server(FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make()->build(), Str::make('title')->deprecated()->build(), Str::make('body')->build()],
        )));

        foreach (['ArticlesAttributes', 'ArticlesCreateAttributes', 'ArticlesUpdateAttributes'] as $component) {
            self::assertTrue($this->at($schemas, $component, 'properties', 'title', 'deprecated'), $component);
            self::assertArrayNotHasKey('deprecated', $this->arrAt($schemas, $component, 'properties', 'body'), $component);
        }
    }

    #[Test]
    public function aDeprecatedObjectChildIsMarkedInsideItsParent(): void
    {
        $schemas = $this->schemas($this->server(FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make()->build(), Obj::make('seo')->fields(Str::make('slug')->deprecated(), Str::make('keywords'))->build()],
        )));

        foreach (['ArticlesAttributes', 'ArticlesCreateAttributes', 'ArticlesUpdateAttributes'] as $component) {
            $seo = $this->arrAt($schemas, $component, 'properties', 'seo');
            self::assertArrayNotHasKey('deprecated', $seo, $component);
            self::assertTrue($this->at($seo, 'properties', 'slug', 'deprecated'), $component);
            self::assertArrayNotHasKey('deprecated', $this->arrAt($seo, 'properties', 'keywords'), $component);
        }
    }

    #[Test]
    public function aDeprecatedRelationIsMarkedInTheReadAndWriteRelationships(): void
    {
        $schemas = $this->schemas($this->server(FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make()->build()],
            relations: [
                new FakeRelationMetadata('author', ['people'], false, deprecated: true),
                new FakeRelationMetadata('tags', ['people'], true, deprecated: true),
                FakeRelationMetadata::toMany('editors', ['people']),
            ],
        )));

        foreach (['ArticlesResource', 'ArticlesCreateRequest', 'ArticlesUpdateRequest'] as $component) {
            $relationships = $component === 'ArticlesResource'
                ? $this->arrAt($schemas, $component, 'properties', 'relationships', 'properties')
                : $this->arrAt($schemas, $component, 'properties', 'data', 'properties', 'relationships', 'properties');
            self::assertSame(['$ref' => '#/components/schemas/ArticlesAuthorRelationship', 'deprecated' => true], $relationships['author'] ?? null, $component);
            self::assertSame(['$ref' => '#/components/schemas/ArticlesTagsRelationship', 'deprecated' => true], $relationships['tags'] ?? null, $component);
            self::assertSame(['$ref' => '#/components/schemas/ArticlesEditorsRelationship'], $relationships['editors'] ?? null, $component);
        }
    }

    #[Test]
    public function aDeprecatedFilterMarksItsParameter(): void
    {
        $parameters = $this->collectionParameters(filters: [
            Where::make('title')->deprecated()->build(),
            Contains::make('body')->deprecated()->build(),
            WhereIn::make('status')->build(),
            WhereHas::make('author')->deprecated(),
        ]);

        self::assertTrue($this->at($parameters, 'filter[title]', 'deprecated'));
        self::assertTrue($this->at($parameters, 'filter[body]', 'deprecated'));
        self::assertTrue($this->at($parameters, 'filter[author]', 'deprecated'));
        self::assertArrayNotHasKey('deprecated', $this->arrAt($parameters, 'filter[status]'));
    }

    #[Test]
    public function deprecatedSortKeysAreListedWithoutTheirDirection(): void
    {
        $parameters = $this->collectionParameters(sorts: [
            SortByField::make('title')->deprecated(),
            SortByField::make('createdAt'),
            SortByField::make('rank')->deprecated(),
        ]);

        $schema = $this->arrAt($parameters, 'sort', 'schema');
        self::assertSame(['title', 'rank'], $schema['x-deprecated-sorts'] ?? null);
        self::assertSame(['title', '-title', 'createdAt', '-createdAt', 'rank', '-rank'], $this->at($schema, 'items', 'enum'));
        self::assertArrayNotHasKey('deprecated', $this->arrAt($parameters, 'sort'));
    }

    #[Test]
    public function aDeprecatedSortFamilyIsFlaggedInItsOwnEntry(): void
    {
        $parameters = $this->collectionParameters(sorts: [
            SortByField::make('title'),
            $this->family('value')->withSuffixPattern('[a-z_]+')->deprecated(),
            $this->family('custom'),
        ]);

        $schema = $this->arrAt($parameters, 'sort', 'schema');
        self::assertSame(
            [
                ['prefix' => 'value', 'suffixPattern' => '[a-z_]+', 'deprecated' => true],
                ['prefix' => 'custom', 'suffixPattern' => '[^,]+'],
            ],
            $schema['x-sort-families'] ?? null,
        );
        self::assertArrayNotHasKey('x-deprecated-sorts', $schema);
    }

    #[Test]
    public function aSortParameterWithNothingDeprecatedCarriesNoList(): void
    {
        $parameters = $this->collectionParameters(sorts: [SortByField::make('title')]);

        self::assertSame(
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['title', '-title']]],
            $this->at($parameters, 'sort', 'schema'),
        );
    }

    #[Test]
    public function aSortableDeprecatedFieldDerivesADeprecatedSort(): void
    {
        $resource = new class extends AbstractResource {
            public static string $type = 'articles';

            public function fields(): array
            {
                return [
                    Id::make(),
                    Str::make('title')->sortable()->deprecated(),
                    Str::make('body')->sortable(),
                ];
            }
        };

        $deprecated = [];
        foreach ($resource->allSorts() as $sort) {
            self::assertInstanceOf(SortByField::class, $sort);
            $deprecated[$sort->key()] = $sort->isDeprecated();
        }

        self::assertSame(['title' => true, 'body' => false], $deprecated);
    }

    #[Test]
    public function aDocumentWithAMarkerDeclaresTheDeprecationTier(): void
    {
        $marked = FakeTypeMetadata::resource(type: 'articles', fields: [Id::make()->build(), Obj::make('seo')->fields(Str::make('slug')->deprecated())->build()]);

        self::assertSame(
            ['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::DEPRECATION)],
            $this->stamp(new FakeServerMetadata(title: 'Blog API', version: '1.0.0', types: [$marked])),
        );
    }

    /**
     * @return iterable<string, array{TypeMetadataInterface}>
     */
    public static function unmarkedTypes(): iterable
    {
        $articles = FakeTypeMetadata::resource(type: 'articles', fields: [Id::make()->build(), Str::make('title')->build()]);

        yield 'nothing declared' => [$articles];
        yield 'a sunset alone' => [new DeprecatedTypeMetadata($articles, sunset: new \DateTimeImmutable('2027-06-30T00:00:00Z'))];
        yield 'a hidden deprecated field' => [FakeTypeMetadata::resource(type: 'articles', fields: [Id::make()->build(), Str::make('secret')->hidden()->deprecated()->build()])];
    }

    #[Test]
    #[DataProvider('unmarkedTypes')]
    public function aDocumentWithoutAMarkerKeepsItsContract(TypeMetadataInterface $type): void
    {
        self::assertSame(
            ['contract' => GeneratorContract::BASELINE],
            $this->stamp(new FakeServerMetadata(title: 'Blog API', version: '1.0.0', types: [$type])),
        );
    }

    #[Test]
    public function theSchemaKeywordIsEmittedBesideTheDescription(): void
    {
        self::assertSame(
            ['type' => 'string', 'description' => 'Old.', 'deprecated' => true],
            Schema::ofType('string')->withDeprecated()->withDescription('Old.')->toArray(),
        );
    }

    /**
     * @return array<mixed>
     */
    private function stamp(FakeServerMetadata $server): array
    {
        return $this->arrAt((new OpenApiProjector())->project($server)->toArray(), 'info', 'x-generator');
    }

    private function articles(): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make()->build(), Str::make('title')->build()],
            relations: [FakeRelationMetadata::toOne('author', ['people'])],
            tags: ['Articles'],
            actions: [new FakeActionMetadata('publish')],
        );
    }

    private function server(TypeMetadataInterface $articles): FakeServerMetadata
    {
        $people = FakeTypeMetadata::resource(type: 'people', fields: [Id::make()->build(), Str::make('name')->build()], tags: ['People']);

        return new FakeServerMetadata(title: 'Blog API', version: '1.0.0', types: [$articles, $people]);
    }

    private function family(string $prefix): SortFamily
    {
        return SortFamily::make($prefix, static fn(string $suffix): SortInterface => SortByField::make($prefix . '.' . $suffix));
    }

    /**
     * @param list<\haddowg\JsonApi\Resource\Filter\FilterInterface> $filters
     * @param list<SortInterface>                                    $sorts
     *
     * @return array<string, array<string, mixed>> the collection endpoint's parameters, keyed by name
     */
    private function collectionParameters(array $filters = [], array $sorts = []): array
    {
        $articles = FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make()->build(), Str::make('title')->build()],
            filters: $filters,
            sorts: $sorts,
        );
        $paths = $this->paths(new FakeServerMetadata(title: 'Blog API', version: '1.0.0', types: [$articles]));

        $parameters = [];
        foreach ($this->arrAt($paths, '/articles', 'get', 'parameters') as $parameter) {
            self::assertIsArray($parameter);
            self::assertIsString($parameter['name']);
            $parameters[$parameter['name']] = $parameter;
        }

        /** @var array<string, array<string, mixed>> */
        return $parameters;
    }

    /**
     * @return array<mixed>
     */
    private function paths(FakeServerMetadata $server): array
    {
        return $this->arrAt((new OpenApiProjector())->project($server)->toArray(), 'paths');
    }

    /**
     * @return array<mixed>
     */
    private function schemas(FakeServerMetadata $server): array
    {
        return $this->arrAt((new OpenApiProjector())->project($server)->toArray(), 'components', 'schemas');
    }

    /**
     * The value at `$keys`, or `null` when any of them is missing.
     *
     * @param array<mixed> $data
     */
    private function at(array $data, string ...$keys): mixed
    {
        $value = $data;
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * The array at `$keys`, failing the test when it is missing.
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>
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
