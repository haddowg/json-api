<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\FilterFamily;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(OperationProjector::class)]
#[CoversClass(GeneratorContract::class)]
final class FilterListAndFamilyProjectionTest extends TestCase
{
    #[Test]
    public function aSetFilterWithoutAListKeepsItsParameterAndTheBaselineContract(): void
    {
        $document = $this->document([WhereIn::make('labels')->build(), WhereIdIn::make()->build()]);

        self::assertSame(
            ['name' => 'filter[labels]', 'in' => 'query', 'description' => 'Filter the collection by `labels`.', 'schema' => ['type' => 'array', 'items' => ['type' => 'string']], 'style' => 'form', 'explode' => false],
            $this->parameter($document, '/campaigns', 'filter[labels]'),
        );
        self::assertNull($this->findParameter($document, '/campaigns', 'filter[labels][]'));
        self::assertNull($this->findParameter($document, '/campaigns', 'filter'));
        self::assertSame(['contract' => GeneratorContract::BASELINE], $this->arrAt($document, 'info', 'x-generator'));
    }

    #[Test]
    public function aDelimitedListDocumentsBothForms(): void
    {
        $document = $this->document([WhereIn::make('budget')->acceptsList()->example(['1', '2'])->build()]);

        self::assertSame(
            ['type' => 'array', 'items' => ['type' => 'integer']],
            $this->arrAt($this->parameter($document, '/campaigns', 'filter[budget]'), 'schema'),
        );
        self::assertSame(
            [
                'name' => 'filter[budget][]',
                'in' => 'query',
                'description' => 'Filter the collection by `budget`.',
                'schema' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'style' => 'form',
                'explode' => true,
                'example' => ['1', '2'],
                'x-filter-list' => ['key' => 'budget', 'delimited' => true],
            ],
            $this->parameter($document, '/campaigns', 'filter[budget][]'),
        );
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::LIST_FILTERS)], $this->arrAt($document, 'info', 'x-generator'));
    }

    #[Test]
    public function anUndelimitedListDocumentsOnlyTheArrayForm(): void
    {
        $document = $this->document([WhereIn::make('columns')->acceptsList(delimited: false)->deprecated()->build()]);

        self::assertNull($this->findParameter($document, '/campaigns', 'filter[columns]'));
        $parameter = $this->parameter($document, '/campaigns', 'filter[columns][]');
        self::assertTrue($parameter['deprecated'] ?? null);
        self::assertSame(['key' => 'columns', 'delimited' => false], $parameter['x-filter-list'] ?? null);
    }

    #[Test]
    public function familiesAreOneDeepObjectParameterMatchedByPattern(): void
    {
        $document = $this->document([
            Where::make('title')->build(),
            $this->family('value')->acceptsList(),
            $this->family('exact')->acceptsList(delimited: false)->withSuffixPattern('[a-z_]+'),
            $this->family('meta')->deprecated(),
        ]);

        self::assertNotNull($this->findParameter($document, '/campaigns', 'filter[title]'));
        self::assertSame(
            [
                'name' => 'filter',
                'in' => 'query',
                'description' => 'Filter the collection by keys a filter family answers: `filter[<prefix>.<suffix>]`, the suffix naming the value to filter on. A family that takes a list accepts `filter[<prefix>.<suffix>][]` once per member, and a comma-separated string unless its `x-filter-families` entry says `delimited: false`.',
                'schema' => [
                    'type' => 'object',
                    'patternProperties' => [
                        '^value\.(?:.+)$' => ['anyOf' => [['type' => 'string'], ['type' => 'array', 'items' => ['type' => 'string']]]],
                        '^exact\.(?:[a-z_]+)$' => ['type' => 'array', 'items' => ['type' => 'string']],
                        '^meta\.(?:.+)$' => ['type' => 'string'],
                    ],
                    'x-filter-families' => [
                        ['prefix' => 'value', 'suffixPattern' => '.+', 'list' => true, 'delimited' => true],
                        ['prefix' => 'exact', 'suffixPattern' => '[a-z_]+', 'list' => true, 'delimited' => false],
                        ['prefix' => 'meta', 'suffixPattern' => '.+', 'list' => false, 'deprecated' => true],
                    ],
                ],
                'style' => 'deepObject',
                'explode' => true,
            ],
            $this->parameter($document, '/campaigns', 'filter'),
        );
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::FILTER_FAMILIES, GeneratorContract::DEPRECATION)], $this->arrAt($document, 'info', 'x-generator'));
    }

    #[Test]
    public function theFamilyPatternsAcceptWhatTheMatcherAccepts(): void
    {
        $schema = \json_decode(\json_encode($this->arrAt($this->parameter($this->document([
            $this->family('value')->acceptsList(delimited: false)->withSuffixPattern('[a-z_]+'),
        ]), '/campaigns', 'filter'), 'schema'), flags: \JSON_THROW_ON_ERROR));
        self::assertIsObject($schema);

        // The object stays open, since the exact filters travel in their own parameters;
        // a key the pattern matches must carry the family's value shape.
        $validator = new Validator();
        self::assertTrue($validator->validate((object) ['value.end_date' => ['a,b']], $schema)->isValid());
        self::assertFalse($validator->validate((object) ['value.end_date' => 'a,b'], $schema)->isValid());
        self::assertTrue($validator->validate((object) ['value.End' => 'a,b'], $schema)->isValid());
    }

    #[Test]
    public function aRelationFamilyReachesTheRelatedEndpointBesideAnExactFilterOfTheSameKey(): void
    {
        $document = $this->document(
            [Where::make('value')->build()],
            [new FakeRelationMetadata('children', ['campaigns'], true, filters: [$this->family('value')])],
        );

        self::assertNotNull($this->findParameter($document, '/campaigns/{id}/children', 'filter[value]'));
        self::assertSame(
            [['prefix' => 'value', 'suffixPattern' => '.+', 'list' => false]],
            $this->arrAt($this->parameter($document, '/campaigns/{id}/children', 'filter'), 'schema', 'x-filter-families'),
        );
    }

    #[Test]
    public function aCollectionActionTakingFiltersCarriesListsAndFamilies(): void
    {
        $document = $this->document(
            [WhereIn::make('columns')->acceptsList(delimited: false)->build(), $this->family('value')],
            actions: [new FakeActionMetadata('count', ['GET'], ActionScope::Collection, ActionInputMode::None, responds: [new MetaResult()], example: false, filters: true)],
        );

        self::assertNotNull($this->findParameter($document, '/campaigns/-actions/count', 'filter[columns][]'));
        self::assertNotNull($this->findParameter($document, '/campaigns/-actions/count', 'filter'));
    }

    private function family(string $prefix): FilterFamily
    {
        return FilterFamily::make($prefix, static fn(string $suffix): ?FilterInterface => null);
    }

    /**
     * @param list<FilterInterface>      $filters
     * @param list<FakeRelationMetadata> $relations
     * @param list<FakeActionMetadata>   $actions
     *
     * @return array<array-key, mixed>
     */
    private function document(array $filters, array $relations = [], array $actions = []): array
    {
        return (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [FakeTypeMetadata::resource(
                type: 'campaigns',
                fields: [Id::make()->build(), Str::make('title')->build(), Integer::make('budget')->build()],
                relations: $relations,
                filters: $filters,
                actions: $actions,
            )],
        ))->toArray();
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private function parameter(array $document, string $path, string $name): array
    {
        return $this->findParameter($document, $path, $name) ?? self::fail(\sprintf('%s has no %s parameter.', $path, $name));
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>|null
     */
    private function findParameter(array $document, string $path, string $name): ?array
    {
        foreach ($this->arrAt($document, 'paths', $path, 'get', 'parameters') as $parameter) {
            self::assertIsArray($parameter);
            if (($parameter['name'] ?? null) === $name) {
                return $parameter;
            }
        }

        return null;
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
