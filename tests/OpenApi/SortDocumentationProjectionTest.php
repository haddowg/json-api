<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\EnumDescriptionMode;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortFamily;
use haddowg\JsonApi\Resource\Sort\SortInterface;
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
#[CoversClass(SortByField::class)]
#[CoversClass(SortFamily::class)]
final class SortDocumentationProjectionTest extends TestCase
{
    #[Test]
    public function describedSortsAreTabledAndMappedLikeDescribedEnumValues(): void
    {
        $sort = $this->sortParameter($this->document([
            SortByField::make('title')->describedAs('Alphabetical by title.'),
            SortByField::make('budget'),
            $this->family('value')->describedAs('By a custom field value.'),
        ]));

        self::assertSame(
            "A comma-separated list of sort fields. Prefix a field with `-` for descending order.\n\n"
            . "| Sort | Description |\n| --- | --- |\n| `title` | Alphabetical by title. |\n| `budget` |  |\n| `value.<suffix>` | By a custom field value. |",
            $sort['description'],
        );
        $schema = $this->arr($sort['schema']);
        self::assertSame(['title' => 'Alphabetical by title.'], $schema['x-sort-descriptions']);
        self::assertSame([['prefix' => 'value', 'suffixPattern' => '[^,]+', 'description' => 'By a custom field value.']], $schema['x-sort-families']);
    }

    #[Test]
    public function undescribedSortsKeepTheBareDescription(): void
    {
        $sort = $this->sortParameter($this->document([SortByField::make('title')]));

        self::assertSame('A comma-separated list of sort fields. Prefix a field with `-` for descending order.', $sort['description']);
        self::assertArrayNotHasKey('x-sort-descriptions', $this->arr($sort['schema']));
    }

    #[Test]
    public function theExtensionsModeLeavesTheDescriptionAlone(): void
    {
        $sort = $this->sortParameter($this->document([SortByField::make('title')->describedAs('By title.')], mode: EnumDescriptionMode::Extensions));

        self::assertSame('A comma-separated list of sort fields. Prefix a field with `-` for descending order.', $sort['description']);
        self::assertSame(['title' => 'By title.'], $this->arr($sort['schema'])['x-sort-descriptions']);
    }

    #[Test]
    public function theDescriptionModeLeavesOutTheMap(): void
    {
        $sort = $this->sortParameter($this->document([SortByField::make('title')->describedAs('By title.')], mode: EnumDescriptionMode::Description));

        self::assertIsString($sort['description']);
        self::assertStringContainsString('| `title` | By title. |', $sort['description']);
        self::assertArrayNotHasKey('x-sort-descriptions', $this->arr($sort['schema']));
    }

    #[Test]
    public function theExampleIsTheFirstKeyWithoutADefaultOrder(): void
    {
        $sort = $this->sortParameter($this->document([$this->family('value'), SortByField::make('title'), SortByField::make('budget')]));

        self::assertSame(['title'], $sort['example']);
    }

    #[Test]
    public function onlyFamiliesGiveNoExample(): void
    {
        self::assertArrayNotHasKey('example', $this->sortParameter($this->document([$this->family('value')])));
    }

    #[Test]
    public function theExampleIsTheTypesDefaultOrder(): void
    {
        $title = SortByField::make('title');
        $sort = $this->sortParameter($this->document(
            [$title, SortByField::make('budget')],
            defaultSort: [new SortDirective(SortByField::make('budget'), true), new SortDirective($title, false)],
        ));

        self::assertSame(['-budget', 'title'], $sort['example']);
        self::assertArrayNotHasKey('default', $this->arr($sort['schema']));
    }

    #[Test]
    public function aDeclaredExampleWinsOverTheDefaultOrder(): void
    {
        $sort = $this->sortParameter($this->document(
            [SortByField::make('title'), SortByField::make('budget')],
            defaultSort: [new SortDirective(SortByField::make('budget'), true)],
            sortExample: ['title', '-budget'],
        ));

        self::assertSame(['title', '-budget'], $sort['example']);
    }

    #[Test]
    public function aRelationShowsItsOwnDefaultOrderThenItsRelatedTypes(): void
    {
        $sorts = [SortByField::make('title'), SortByField::make('position')];
        $own = $this->relatedDocument(new FakeRelationMetadata('tracks', ['tracks'], true, defaultSort: [new SortDirective(SortByField::make('position'), false)]), $sorts, ['-title']);
        $inherited = $this->relatedDocument(new FakeRelationMetadata('tracks', ['tracks'], true), $sorts, ['-title']);
        $first = $this->relatedDocument(new FakeRelationMetadata('tracks', ['tracks'], true), $sorts, null);

        foreach (['/albums/{id}/tracks', '/albums/{id}/relationships/tracks'] as $path) {
            self::assertSame(['position'], $this->sortParameter($own, $path)['example'], $path);
            self::assertSame(['-title'], $this->sortParameter($inherited, $path)['example'], $path);
            self::assertSame(['title'], $this->sortParameter($first, $path)['example'], $path);
        }
    }

    #[Test]
    public function aPivotSortIsDescribedOnTheRelationsEndpoints(): void
    {
        $document = $this->relatedDocument(
            new FakeRelationMetadata('tracks', ['tracks'], true, sorts: [SortByField::make('addedAt')->describedAs('When the track joined the album.')]),
            [SortByField::make('title')],
            null,
        );

        self::assertSame(
            ['addedAt' => 'When the track joined the album.'],
            $this->arr($this->sortParameter($document, '/albums/{id}/tracks')['schema'])['x-sort-descriptions'],
        );
    }

    #[Test]
    public function describedAsSurvivesDeprecation(): void
    {
        $sort = SortByField::make('title')->describedAs('By title.')->deprecated();
        $family = $this->family('value')->describedAs('By value.')->deprecated()->withSuffixPattern('[a-z]+');

        self::assertSame('By title.', $sort->getDescription());
        self::assertTrue($sort->isDeprecated());
        self::assertSame('By value.', $family->getDescription());
        self::assertTrue($family->isDeprecated());
    }

    private function family(string $prefix): SortFamily
    {
        return SortFamily::make($prefix, static fn(string $suffix): ?SortInterface => null);
    }

    /**
     * @param list<SortInterface> $sorts
     * @param list<SortDirective> $defaultSort
     * @param list<string>|null   $sortExample
     *
     * @return array<array-key, mixed>
     */
    private function document(array $sorts, array $defaultSort = [], ?array $sortExample = null, EnumDescriptionMode $mode = EnumDescriptionMode::Both): array
    {
        $schemas = new SchemaProjector(enumDescriptionMode: $mode);

        return (new OpenApiProjector($schemas, new OperationProjector($schemas)))->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [FakeTypeMetadata::resource(
                type: 'campaigns',
                fields: [Id::make()->build(), Str::make('title')->build(), Str::make('budget')->build()],
                sorts: $sorts,
                defaultSort: $defaultSort,
                sortExample: $sortExample,
            )],
        ))->toArray();
    }

    /**
     * @param list<SortInterface> $relatedSorts
     * @param list<string>|null   $relatedExample
     *
     * @return array<array-key, mixed>
     */
    private function relatedDocument(FakeRelationMetadata $relation, array $relatedSorts, ?array $relatedExample): array
    {
        return (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(type: 'albums', fields: [Id::make()->build()], relations: [$relation]),
                FakeTypeMetadata::resource(type: 'tracks', fields: [Id::make()->build(), Str::make('title')->build()], sorts: $relatedSorts, sortExample: $relatedExample),
            ],
        ))->toArray();
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private function sortParameter(array $document, string $path = '/campaigns'): array
    {
        foreach ($this->arr($this->arr($this->arr($this->arr($document['paths'] ?? null)[$path] ?? null)['get'] ?? null)['parameters'] ?? null) as $parameter) {
            $parameter = $this->arr($parameter);
            if ($parameter['name'] === 'sort') {
                return $parameter;
            }
        }

        self::fail($path . ' has no sort parameter.');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
