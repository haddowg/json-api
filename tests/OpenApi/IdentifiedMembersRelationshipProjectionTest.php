<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\RelationFacts;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A `MorphToMany` declared with `identifiedBy()` pages its stored pairs on its relationship
 * endpoint like its related endpoint, so that operation takes the same `page` and
 * `withCount` parameters. With pivot fields on its link rows both endpoints filter and sort
 * by them and its identifiers type `meta.pivot`. Every other polymorphic relationship
 * endpoint takes none.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(OperationProjector::class)]
#[CoversClass(RelationFacts::class)]
final class IdentifiedMembersRelationshipProjectionTest extends TestCase
{
    #[Test]
    public function theRelationshipEndpointTakesTheRelatedEndpointsPageParameters(): void
    {
        $paths = $this->arrAt($this->document(FakeRelationMetadata::identifiedMorphToMany('companies', ['people', 'robots'], countable: true, pageSchema: PagePaginator::make()->describePageSchema())), 'paths');

        $related = $this->parameters($paths, '/articles/{id}/companies');
        $relationship = $this->parameters($paths, '/articles/{id}/relationships/companies');

        self::assertSame(['page'], \array_keys(\array_intersect_key($relationship, ['page' => true])));
        self::assertSame($related['page'], $relationship['page']);
        self::assertSame(\array_key_exists('withCount', $related), \array_key_exists('withCount', $relationship));
        self::assertArrayNotHasKey('filter[name]', $relationship);
        self::assertArrayNotHasKey('sort', $relationship);
    }

    #[Test]
    public function linkRowPivotsFilterAndSortBothEndpointsAndTypeTheIdentifiers(): void
    {
        $document = $this->document(self::pivoted());
        $paths = $this->arrAt($document, 'paths');

        foreach (['/articles/{id}/companies', '/articles/{id}/relationships/companies'] as $path) {
            $parameters = $this->parameters($paths, $path);
            self::assertArrayHasKey('filter[minRank]', $parameters, $path);
            self::assertSame(['rank', '-rank'], $this->arrAt($parameters, 'sort', 'schema', 'items', 'enum'), $path);
            self::assertArrayNotHasKey('filter[name]', $parameters, $path);
        }

        $items = $this->arrAt($document, 'components', 'schemas', 'ArticlesCompaniesRelationship', 'properties', 'data', 'items');
        self::assertSame(
            ['type' => 'object', 'properties' => ['meta' => ['type' => 'object', 'properties' => ['pivot' => ['$ref' => '#/components/schemas/ArticlesCompaniesPivot']]]]],
            $this->arrAt($items, 'allOf', '1'),
        );
        self::assertArrayHasKey('oneOf', $this->arrAt($items, 'allOf', '0'));
        self::assertSame(['rank'], \array_keys($this->arrAt($document, 'components', 'schemas', 'ArticlesCompaniesWritePivot', 'properties')));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::IDENTIFIED_PIVOTS)], $this->arrAt($document, 'info', 'x-generator'));
    }

    #[Test]
    public function anIdentifiedRelationWithoutLinkRowQueriesKeepsTheBaselineContract(): void
    {
        $document = $this->document(FakeRelationMetadata::identifiedMorphToMany('companies', ['people', 'robots'], countable: true, pageSchema: PagePaginator::make()->describePageSchema()));

        self::assertSame(['contract' => GeneratorContract::BASELINE], $this->arrAt($document, 'info', 'x-generator'));
        self::assertArrayNotHasKey('ArticlesCompaniesPivot', $this->arrAt($document, 'components', 'schemas'));
    }

    #[Test]
    public function aPlainPolymorphicToManyWithPivotFieldsTypesNoPivot(): void
    {
        $document = $this->document(new FakeRelationMetadata('companies', ['people', 'robots'], true, pivotFields: [Integer::make('rank')->build()], polymorphic: true));

        self::assertArrayNotHasKey('ArticlesCompaniesPivot', $this->arrAt($document, 'components', 'schemas'));
    }

    private static function pivoted(): FakeRelationMetadata
    {
        return new FakeRelationMetadata(
            'companies',
            ['people', 'robots'],
            true,
            countable: true,
            pageSchema: PagePaginator::make()->describePageSchema(),
            filters: [Where::make('minRank', 'pivot.rank', '>=')->integer()->build()],
            sorts: [SortByField::make('rank')],
            pivotFields: [Integer::make('rank')->required()->build(), Str::make('since')->readOnly()->build()],
            polymorphic: true,
            identifiesMembers: true,
        );
    }

    #[Test]
    public function aPlainPolymorphicToManyRelationshipEndpointStillTakesNone(): void
    {
        $paths = $this->arrAt($this->document(new FakeRelationMetadata('companies', ['people', 'robots'], true, countable: true, pageSchema: PagePaginator::make()->describePageSchema(), polymorphic: true)), 'paths');

        self::assertSame([], $this->parameters($paths, '/articles/{id}/relationships/companies'));
    }

    #[Test]
    public function theRelatedEndpointIsUnchanged(): void
    {
        $page = PagePaginator::make()->describePageSchema();
        $identified = $this->arrAt($this->document(FakeRelationMetadata::identifiedMorphToMany('companies', ['people', 'robots'], countable: true, pageSchema: $page)), 'paths', '/articles/{id}/companies');
        $plain = $this->arrAt($this->document(new FakeRelationMetadata('companies', ['people', 'robots'], true, countable: true, pageSchema: $page, polymorphic: true)), 'paths', '/articles/{id}/companies');

        self::assertSame($plain, $identified);
    }

    /**
     * @param array<array-key, mixed> $paths
     *
     * @return array<string, mixed>
     */
    private function parameters(array $paths, string $path): array
    {
        $operation = $this->arrAt($paths, $path, 'get');
        $parameters = $operation['parameters'] ?? [];
        self::assertIsArray($parameters);
        $byName = [];
        foreach ($parameters as $parameter) {
            self::assertIsArray($parameter);
            self::assertIsString($parameter['name']);
            $byName[$parameter['name']] = $parameter;
        }

        return $byName;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(RelationMetadataInterface $relation): array
    {
        $person = static fn(string $type, string $id): FakeTypeMetadata => FakeTypeMetadata::resource(
            type: $type,
            fields: [Id::make()->example($id)->build(), Str::make('name')->example('Ada')->build()],
            filters: [Where::make('name')->example('Ada')->build()],
            sorts: [SortByField::make('name')],
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
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }
        self::assertIsArray($value);

        return $value;
    }
}
