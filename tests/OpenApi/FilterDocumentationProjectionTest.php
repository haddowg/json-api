<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\FilterFamily;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(OperationProjector::class)]
#[CoversClass(FilterFamily::class)]
final class FilterDocumentationProjectionTest extends TestCase
{
    #[Test]
    public function aDescribedPresenceFilterKeepsItsDescriptionAndSaysItIsAppliedWhenPresent(): void
    {
        $document = $this->document([
            WhereHas::make('hasTags', 'tags')->describedAs('Campaigns with at least one tag.'),
            WhereDoesntHave::make('untagged', 'tags')->describedAs('Campaigns without tags.'),
            WhereNull::make('unbudgeted', 'budget')->describedAs('Campaigns without a budget.'),
            WhereNotNull::make('budgeted', 'budget')->describedAs('Campaigns with a budget.'),
        ]);

        foreach ([
            'hasTags' => 'Campaigns with at least one tag.',
            'untagged' => 'Campaigns without tags.',
            'unbudgeted' => 'Campaigns without a budget.',
            'budgeted' => 'Campaigns with a budget.',
        ] as $key => $description) {
            $parameter = $this->parameter($document, '/campaigns', 'filter[' . $key . ']');
            self::assertSame($description . ' Applied when present; value ignored.', $parameter['description'], $key);
            self::assertSame('true', $parameter['example'], $key);
        }
    }

    #[Test]
    public function anUndescribedPresenceFilterFallsBackToTheGenericLine(): void
    {
        $parameter = $this->parameter($this->document([WhereNull::make('unbudgeted', 'budget')]), '/campaigns', 'filter[unbudgeted]');

        self::assertSame('Filter the collection by `unbudgeted`. Applied when present; value ignored.', $parameter['description']);
        self::assertSame('true', $parameter['example']);
    }

    #[Test]
    public function aFixedWhereShowsTrueWhateverExampleItDeclares(): void
    {
        $parameter = $this->parameter(
            $this->document([Where::make('live', 'status')->fixed('live')->example('anything')->describedAs('Live campaigns only.')->build()]),
            '/campaigns',
            'filter[live]',
        );

        self::assertSame('Live campaigns only. Applied when present; value ignored.', $parameter['description']);
        self::assertSame('true', $parameter['example']);
    }

    #[Test]
    public function describedAsCarriesThroughDeprecation(): void
    {
        $filter = WhereHas::make('hasTags', 'tags')->describedAs('Tagged.')->deprecated();

        self::assertSame('Tagged.', $filter->getDescription());
        self::assertTrue($filter->isDeprecated());
    }

    #[Test]
    public function theDescriptionAndExampleStayOnTheParameter(): void
    {
        $parameter = $this->parameter(
            $this->document([Where::make('title')->describedAs('By title.')->example('Spring')->build()]),
            '/campaigns',
            'filter[title]',
        );

        self::assertSame('By title.', $parameter['description']);
        self::assertSame('Spring', $parameter['example']);
        self::assertSame(['type' => 'string'], $parameter['schema']);
    }

    #[Test]
    public function aDescribedAndExemplifiedFamilyIsListedOnItsEntryAndTheSharedParameter(): void
    {
        $document = $this->document([
            $this->family('value')->withSuffixPattern('[a-z_]+')->describedAs('Matches a custom field value.')->example('cap', '10'),
            $this->family('meta')->acceptsList()->example('tier', ['gold', 'silver']),
            $this->family('plain'),
        ]);
        $parameter = $this->parameter($document, '/campaigns', 'filter');

        self::assertSame(
            [
                ['prefix' => 'value', 'suffixPattern' => '[a-z_]+', 'list' => false, 'description' => 'Matches a custom field value.', 'example' => ['value.cap' => '10']],
                ['prefix' => 'meta', 'suffixPattern' => '.+', 'list' => true, 'delimited' => true, 'example' => ['meta.tier' => ['gold', 'silver']]],
                ['prefix' => 'plain', 'suffixPattern' => '.+', 'list' => false],
            ],
            $this->arrAt($parameter, 'schema', 'x-filter-families'),
        );
        self::assertIsString($parameter['description']);
        self::assertStringEndsWith("\n\n- `filter[value.<suffix>]`: Matches a custom field value.", $parameter['description']);
        self::assertSame(['value.cap' => '10', 'meta.tier' => ['gold', 'silver']], $parameter['example']);
    }

    #[Test]
    public function familiesWithoutDocumentationLeaveTheSharedParameterAsItWas(): void
    {
        $parameter = $this->parameter($this->document([$this->family('value')]), '/campaigns', 'filter');

        self::assertArrayNotHasKey('example', $parameter);
        self::assertIsString($parameter['description']);
        self::assertStringNotContainsString("\n", $parameter['description']);
    }

    #[Test]
    public function aFamilyExampleMustUseASuffixTheFamilyAccepts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The example suffix "Cap" of filter family "value" does not match its suffix pattern "[a-z_]+".');

        $this->family('value')->withSuffixPattern('[a-z_]+')->example('Cap', '10');
    }

    #[Test]
    public function narrowingTheSuffixPatternRechecksTheExample(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->family('value')->example('Cap', '10')->withSuffixPattern('[a-z_]+');
    }

    #[Test]
    public function aStringListExampleIsSplitForTheArrayForm(): void
    {
        $document = $this->document([WhereIn::make('labels')->acceptsList()->example('red, blue')->build()]);

        self::assertSame(['red', 'blue'], $this->parameter($document, '/campaigns', 'filter[labels]')['example']);
        self::assertSame(['red', 'blue'], $this->parameter($document, '/campaigns', 'filter[labels][]')['example']);
    }

    #[Test]
    public function anArrayListExampleIsJoinedWhereTheDelimitedFormIsAString(): void
    {
        $document = $this->document([WhereIn::make('labels')->delimiter(';')->acceptsList()->example(['red', 'blue'])->build()]);

        $delimited = $this->parameter($document, '/campaigns', 'filter[labels]');
        self::assertSame(['type' => 'string'], $delimited['schema']);
        self::assertSame('red;blue', $delimited['example']);
        self::assertSame(['red', 'blue'], $this->parameter($document, '/campaigns', 'filter[labels][]')['example']);
    }

    #[Test]
    public function aStringExampleOnAnArrayOnlyListReachesTheArrayForm(): void
    {
        $document = $this->document([WhereIn::make('labels')->acceptsList(false)->example('red,blue')->build()]);

        self::assertNull($this->findParameter($document, '/campaigns', 'filter[labels]'));
        self::assertSame(['red,blue'], $this->parameter($document, '/campaigns', 'filter[labels][]')['example']);
    }

    #[Test]
    public function aDefaultIsTheParameterSchemasDefault(): void
    {
        $document = $this->document([
            Where::make('status')->default('live')->describedAs('By status.')->build(),
            WhereIn::make('labels')->acceptsList()->default('red,blue')->build(),
            WhereIn::make('tiers')->delimiter(';')->acceptsList()->default(['gold', 'silver'])->build(),
        ]);

        $status = $this->parameter($document, '/campaigns', 'filter[status]');
        self::assertSame(['type' => 'string', 'default' => 'live'], $status['schema']);
        self::assertSame('By status.', $status['description']);

        self::assertSame(['red', 'blue'], $this->arrAt($this->parameter($document, '/campaigns', 'filter[labels]'), 'schema')['default']);
        self::assertSame(['red', 'blue'], $this->arrAt($this->parameter($document, '/campaigns', 'filter[labels][]'), 'schema')['default']);
        self::assertSame('gold;silver', $this->arrAt($this->parameter($document, '/campaigns', 'filter[tiers]'), 'schema')['default']);
        self::assertSame(['gold', 'silver'], $this->arrAt($this->parameter($document, '/campaigns', 'filter[tiers][]'), 'schema')['default']);
    }

    #[Test]
    public function aRelationKeepsTheRelatedDefaultsItDoesNotDrop(): void
    {
        $document = $this->relatedDocument(new FakeRelationMetadata('tags', ['tags'], true));

        foreach (['/campaigns/{id}/tags', '/campaigns/{id}/relationships/tags'] as $path) {
            self::assertSame('live', $this->arrAt($this->parameter($document, $path, 'filter[status]'), 'schema')['default'] ?? null, $path);
            self::assertSame('en', $this->arrAt($this->parameter($document, $path, 'filter[locale]'), 'schema')['default'] ?? null, $path);
        }
    }

    #[Test]
    public function aRelationLeavesOffTheDefaultsItDrops(): void
    {
        $document = $this->relatedDocument(new FakeRelationMetadata('tags', ['tags'], true, droppedFilterDefaults: ['status']));

        foreach (['/campaigns/{id}/tags', '/campaigns/{id}/relationships/tags'] as $path) {
            self::assertArrayNotHasKey('default', $this->arrAt($this->parameter($document, $path, 'filter[status]'), 'schema'), $path);
            self::assertSame('en', $this->arrAt($this->parameter($document, $path, 'filter[locale]'), 'schema')['default'] ?? null, $path);
        }
        // The related type's own collection keeps it.
        self::assertSame('live', $this->arrAt($this->parameter($document, '/tags', 'filter[status]'), 'schema')['default'] ?? null);
    }

    #[Test]
    public function aRelationDroppingEveryDefaultLeavesOffAll(): void
    {
        $document = $this->relatedDocument(new FakeRelationMetadata('tags', ['tags'], true, dropsAllFilterDefaults: true));

        foreach (['filter[status]', 'filter[locale]'] as $name) {
            self::assertArrayNotHasKey('default', $this->arrAt($this->parameter($document, '/campaigns/{id}/tags', $name), 'schema'), $name);
        }
    }

    private function family(string $prefix): FilterFamily
    {
        return FilterFamily::make($prefix, static fn(string $suffix): ?FilterInterface => null);
    }

    /**
     * @param list<FilterInterface> $filters
     *
     * @return array<array-key, mixed>
     */
    private function document(array $filters): array
    {
        return (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [FakeTypeMetadata::resource(
                type: 'campaigns',
                fields: [Id::make()->build(), Str::make('title')->build(), Str::make('budget')->build()],
                filters: $filters,
            )],
        ))->toArray();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function relatedDocument(FakeRelationMetadata $relation): array
    {
        return (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(type: 'campaigns', fields: [Id::make()->build()], relations: [$relation]),
                FakeTypeMetadata::resource(
                    type: 'tags',
                    fields: [Id::make()->build(), Str::make('status')->build(), Str::make('locale')->build()],
                    filters: [Where::make('status')->default('live')->build(), Where::make('locale')->default('en')->build()],
                ),
            ],
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
