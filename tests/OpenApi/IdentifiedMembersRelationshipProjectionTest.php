<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\RelationFacts;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\Field\Id;
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
 * `withCount` parameters. Every other polymorphic relationship endpoint takes none.
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
