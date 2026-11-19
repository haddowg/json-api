<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Through;

use haddowg\JsonApi\Collection\Keyset\KeysetResolver;
use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\RelationHop;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Filter\ThroughReadAccess;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Filter\WhereSet;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Through\FiltersThrough;
use haddowg\JsonApi\Resource\Through\RelationPath;
use haddowg\JsonApi\Tests\Double\ReversingIdEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Filters and sorts declared with `->through()`: resolution against the relation graph,
 * the refusals, the leaf a value object reads, and the reference in-memory handlers'
 * EXISTS and to-one ordering.
 *
 * Leads belong to a campaign (to-one) and use templates (to-many); a campaign belongs to
 * an advertiser and to a brand whose ids are encoded.
 */
#[CoversClass(RelationPath::class)]
#[CoversClass(ThroughPathResolver::class)]
#[CoversClass(ArrayFilterHandler::class)]
#[CoversClass(ArraySortHandler::class)]
#[CoversClass(SortByField::class)]
final class ThroughRelationsTest extends TestCase
{
    #[Test]
    public function anIdFilterKeyedByARelationComparesItsRelatedIds(): void
    {
        $filter = $this->resolve(WhereIdIn::make('templates')->build());
        self::assertInstanceOf(WhereIdIn::class, $filter);
        self::assertSame('templates', $filter->throughRelations());
        self::assertEquals([new UuidFormat()], $filter->constraints());
        self::assertSame([1, 3], $this->ids(WhereIdIn::make('templates')->build(), 'a'));
        self::assertSame([2], $this->ids(WhereIdNotIn::make('owningCampaign')->build(), '100,102'));

        // Keyed `id`, or by a name no relation has, it compares the type's own ids.
        self::assertSame([2], $this->ids(WhereIdIn::make()->build(), '2'));
        self::assertSame([2], $this->ids(WhereIdIn::make('lead')->build(), '2'));
    }

    #[Test]
    public function anIdFilterKeyedByARelationWithAnotherColumnIsAmbiguous(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('could mean the relation\'s ids or the type\'s own');

        $this->resolve(WhereIdIn::make('templates', 'templateKey')->build());
    }

    #[Test]
    public function readAccessListsTheHopsOfAThroughFilterAndSort(): void
    {
        $types = self::types();
        $access = new ThroughReadAccess(new ThroughPathResolver(static fn(string $type): ?array => $types[$type] ?? null));

        $hops = $access->hopsOf(WhereDoesntHave::make('unadvertised', 'advertiser')->through('owningCampaign'), $types['leads'], 'leads');
        self::assertSame(
            [['owningCampaign', 'leads', 'campaign'], ['advertiser', 'campaigns', 'campaign.advertiser']],
            \array_map(static fn(RelationHop $hop): array => [$hop->relation->name(), $hop->parentType, $hop->path], $hops),
        );
        self::assertSame(['templates'], \array_map(static fn(RelationHop $hop): string => $hop->relation->name(), $access->hopsOf(WhereIdIn::make('templates')->build(), $types['leads'], 'leads')));
        self::assertSame(
            ['owningCampaign', 'advertiser'],
            \array_map(static fn(RelationHop $hop): string => $hop->relation->name(), $access->hopsOfSort(SortByField::make('advertiserName', 'name')->through('owningCampaign.advertiser'), $types['leads'], 'leads')),
        );
        self::assertSame([], $access->hopsOfSort(SortByField::make('name'), $types['leads'], 'leads'));
    }

    #[Test]
    public function aBuilderCarriesThePathUnresolved(): void
    {
        $filter = DateRange::make('campaignDates', 'startDate')->through('campaign')->build();

        self::assertSame('campaign', $filter->throughRelations());
        self::assertNull($filter->resolvedThrough());
        self::assertSame('startDate', $filter->column);
    }

    #[Test]
    public function anEmptySegmentIsRefusedWhenDeclared(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Where::make('x')->through('campaign..advertiser');
    }

    #[Test]
    public function aPathResolvesByApiNameAndReadsTheLeafFieldsColumn(): void
    {
        $filter = $this->resolve(Where::make('advertiserName', 'name')->through('owningCampaign.advertiser')->build());
        self::assertInstanceOf(Where::class, $filter);

        $path = $filter->resolvedThrough();
        self::assertNotNull($path);
        self::assertSame(['campaign', 'advertiser'], $path->storageSegments());
        self::assertSame('advertisers', $path->leafType());
        self::assertFalse($path->reachesMany());
        self::assertSame('company_name', $filter->atLeaf()->column);
        self::assertNull($filter->atLeaf()->throughRelations());
    }

    #[Test]
    public function aRelationNamedByItsStorageMemberIsNotFound(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"campaign" is not a relation');

        $this->resolve(Where::make('name')->through('campaign')->build());
    }

    #[Test]
    public function aPolymorphicHopIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('polymorphic');

        $this->resolve(Where::make('name')->through('subject')->build());
    }

    #[Test]
    public function anUnregisteredRelatedTypeIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not a registered resource');

        $this->resolve(Where::make('name')->through('ghost')->build());
    }

    #[Test]
    public function aLeafNamingNoFieldIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares no such field');

        $this->resolve(Where::make('cost')->through('owningCampaign')->build());
    }

    #[Test]
    public function aValueFilterRefusesARelationLeaf(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is a relation');

        $this->resolve(Where::make('advertiser')->through('owningCampaign')->build());
    }

    #[Test]
    public function aComputedLeafIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('stores no value');

        $this->resolve(Where::make('label')->through('owningCampaign')->build());
    }

    #[Test]
    public function anIdFilterRefusesALeafThatIsNotTheId(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is not its id');

        $this->resolve(WhereIdIn::make('campaign', 'name')->through('owningCampaign')->build());
    }

    #[Test]
    public function anExistenceFilterRefusesALeafThatIsNotARelation(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is not a relation');

        $this->resolve(WhereHas::make('name')->through('owningCampaign'));
    }

    #[Test]
    public function aSortThroughAToManyIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('crosses a to-many');

        $this->resolver()->resolveSort(SortByField::make('templateName', 'name')->through('templates'), self::types()['leads']);
    }

    #[Test]
    public function aDateRangeOverADateLeafComparesCalendarDays(): void
    {
        $filter = $this->resolve(DateRange::make('campaignDates', 'startDate')->through('owningCampaign')->build());

        self::assertInstanceOf(DateRange::class, $filter);
        self::assertTrue($filter->calendarDays);
        self::assertSame(DateRange::DATE_DESCRIPTION, $filter->getDescription());
        self::assertSame('owningCampaign', $filter->throughRelations());
        self::assertSame('start_date', $filter->atLeaf()->column);
        self::assertTrue($filter->atLeaf()->calendarDays);
    }

    #[Test]
    public function aDateRangeOverADateTimeLeafComparesInstants(): void
    {
        $filter = $this->resolve(DateRange::make('launched', 'launchedAt')->through('owningCampaign')->build());

        self::assertInstanceOf(DateRange::class, $filter);
        self::assertFalse($filter->calendarDays);
    }

    #[Test]
    public function aDateRangeThroughIsNotDecidedByAFieldOfTheFilteredType(): void
    {
        // Leads declare a Date `startDate` of their own; the range reads the campaign's.
        $filter = DateRange::make('launched', 'launchedAt')->through('owningCampaign')->build();

        self::assertFalse($filter->calendarDays);
    }

    #[Test]
    public function anIdLeafDecodesThroughTheReachedTypesEncoderAndTakesItsFormat(): void
    {
        $where = $this->resolve(Where::make('brand', 'id')->through('owningCampaign.brand')->build());
        self::assertInstanceOf(FiltersThrough::class, $where);
        self::assertSame('7', $where->leafValue('7'));

        $reversed = $this->resolve(Where::make('brand', 'id')->through('owningCampaign.encodedBrand')->build());
        self::assertInstanceOf(FiltersThrough::class, $reversed);
        self::assertSame('cba', $reversed->leafValue('abc'));

        $ids = $this->resolve(WhereIdIn::make('template')->through('templates')->build());
        self::assertInstanceOf(WhereIdIn::class, $ids);
        self::assertEquals([new UuidFormat()], $ids->constraints());

        $advertisers = $this->resolve(WhereIdIn::make('advertiser')->through('owningCampaign.advertiser')->build());
        self::assertInstanceOf(WhereIdIn::class, $advertisers);
        self::assertEquals([new Pattern('^[0-9]+$', documentsAs: 'integer')], $advertisers->constraints());

        $encoded = $this->resolve(WhereIdNotIn::make('brand')->through('owningCampaign.encodedBrand')->build());
        self::assertInstanceOf(FiltersThrough::class, $encoded);
        self::assertSame(['cba', 'fed'], $encoded->leafValue('abc,def'));
        self::assertSame(['cba'], $encoded->leafValue(['abc']));
    }

    #[Test]
    public function anIdTheEncoderCannotDecodeIsAFilterValueError(): void
    {
        $filter = $this->resolve(WhereIdIn::make('brand')->through('owningCampaign.encodedBrand')->build());
        self::assertInstanceOf(FiltersThrough::class, $filter);

        $this->expectException(FilterValueInvalid::class);

        $filter->leafValue('');
    }

    #[Test]
    public function withersKeepThePath(): void
    {
        $where = Where::make('advertiserName', 'name')->through('owningCampaign.advertiser')->default('x')->build()->withoutDefault();
        self::assertSame('owningCampaign.advertiser', $where->throughRelations());
        self::assertFalse($where->hasDefault());

        $set = WhereSet::make('archived', 'archivedAt')->through('owningCampaign')->default(false)->build()->withoutDefault();
        self::assertSame('owningCampaign', $set->throughRelations());

        $has = WhereHas::make('advertiser')->through('owningCampaign')->deprecated()->describedAs('x');
        self::assertSame('owningCampaign', $has->throughRelations());
        self::assertTrue($has->isDeprecated());
        self::assertSame('x', $has->getDescription());

        $sort = SortByField::make('advertiserName', 'name')->through('owningCampaign.advertiser')->describedAs('y')->deprecated();
        self::assertSame('owningCampaign.advertiser', $sort->throughRelations());
        self::assertSame('y', $sort->getDescription());
    }

    #[Test]
    public function aToOneHopMatchesTheRelatedRow(): void
    {
        self::assertSame([1, 2], $this->ids(Contains::make('campaignName', 'name')->through('owningCampaign')->build(), 'spring'));
        self::assertSame([1], $this->ids(Where::make('advertiserName', 'name')->through('owningCampaign.advertiser')->build(), 'Acme'));
    }

    #[Test]
    public function aToManyHopMatchesWhenAnyMemberMatches(): void
    {
        self::assertSame([1, 3], $this->ids(WhereIn::make('templateName', 'name')->through('templates')->build(), 'welcome'));
        // Any member outside the list, not "no member in the list".
        self::assertSame([2, 3], $this->ids(WhereNotIn::make('templateName', 'name')->through('templates')->build(), 'welcome'));
        self::assertSame([], $this->ids(WhereNotIn::make('templateName', 'name')->through('templates')->build(), 'welcome,reminder'));
    }

    #[Test]
    public function aMissingRelationNeverMatches(): void
    {
        // Lead 4 has no campaign; every through filter leaves it out, even the null tests.
        self::assertSame([1, 2, 3], $this->ids(WhereNull::make('archived', 'archivedAt')->through('owningCampaign'), ''));
        self::assertSame([], $this->ids(WhereNull::make('advertiserName', 'name')->through('owningCampaign.advertiser'), ''));
        self::assertSame([1, 2], $this->ids(WhereNotNull::make('advertiserName', 'name')->through('owningCampaign.advertiser'), ''));
        self::assertSame([1, 2, 3], $this->ids(WhereSet::make('archived', 'archivedAt')->through('owningCampaign')->build(), 'false'));
        self::assertSame([], $this->ids(WhereSet::make('archived', 'archivedAt')->through('owningCampaign')->build(), 'true'));
    }

    #[Test]
    public function existenceFiltersFindMissingRelationsThroughAPath(): void
    {
        self::assertSame([1, 2], $this->ids(WhereHas::make('advertiser')->through('owningCampaign'), ''));
        self::assertSame([3], $this->ids(WhereDoesntHave::make('advertiser')->through('owningCampaign'), ''));
        // No member at all is the existence filter's job: a lead with no templates.
        self::assertSame([4], $this->ids(WhereDoesntHave::make('templates'), ''));
    }

    #[Test]
    public function aRangeAndIdFiltersApplyAtTheLeaf(): void
    {
        self::assertSame([1, 2], $this->ids(Range::make('budget', 'budget')->through('owningCampaign')->build(), ['min' => '100']));
        self::assertSame([1], $this->ids(WhereIdIn::make('advertiser')->through('owningCampaign.advertiser')->build(), '10'));
        self::assertSame([2, 3], $this->ids(WhereIdNotIn::make('campaign')->through('owningCampaign')->build(), '100'));
        self::assertSame([1, 2], $this->ids(DateRange::make('campaignDates', 'startDate')->through('owningCampaign')->build(), ['min' => '2026-03-01', 'max' => '2026-03-31']));
    }

    #[Test]
    public function aGroupAppliesEachChildsPath(): void
    {
        $group = WhereAny::make(
            'search',
            Where::make('advertiserName', 'name')->through('owningCampaign.advertiser'),
            Where::make('templateName', 'name')->through('templates'),
        )->build();

        self::assertSame([2, 3], $this->ids($group, 'reminder'));
    }

    #[Test]
    public function admissionHidesTheRowsAScopeLeavesOut(): void
    {
        $filter = $this->resolve(Where::make('advertiserName', 'name')->through('owningCampaign.advertiser')->build());
        $handler = new ArrayFilterHandler();
        $walked = [];
        $hideAcme = static function (string $path, array $rows, ?RelationInterface $relation = null) use (&$walked): array {
            $walked[] = $path . ':' . $relation?->name();

            return \array_values(\array_filter($rows, static fn(mixed $row): bool => $path !== 'campaign.advertiser' || !\is_object($row) || ($row->company_name ?? null) !== 'Acme'));
        };

        self::assertSame([], $handler->withRowAdmission($hideAcme)->apply($filter, self::leads(), 'Acme'));
        // The walked storage path and the relation each hop names.
        self::assertContains('campaign:owningCampaign', $walked);
        self::assertContains('campaign.advertiser:advertiser', $walked);
    }

    #[Test]
    public function aSortReadsTheReachedRowAndNullWhenThereIsNone(): void
    {
        $sort = $this->resolver()->resolveSort(SortByField::make('advertiserName', 'name')->through('owningCampaign.advertiser'), self::types()['leads']);
        $handler = new ArraySortHandler();

        $sorted = $handler->apply([new SortDirective($sort, false)], self::leads());
        self::assertIsArray($sorted);
        self::assertSame([3, 4, 1, 2], self::idsOf($sorted));

        $hideAcme = static fn(string $path, array $rows): array => \array_values(\array_filter($rows, static fn(mixed $row): bool => !\is_object($row) || ($row->company_name ?? null) !== 'Acme'));
        $scoped = $handler->withRowAdmission($hideAcme)->apply([new SortDirective($sort, true)], self::leads());
        self::assertIsArray($scoped);
        self::assertSame([2, 1, 3, 4], self::idsOf($scoped));
    }

    #[Test]
    public function aCursorLevelReadsTheSortsValue(): void
    {
        $sort = $this->resolver()->resolveSort(SortByField::make('advertiserName', 'name')->through('owningCampaign.advertiser'), self::types()['leads']);

        $columns = (new KeysetResolver())->resolve(['advertiserName'], [$sort], [], 'id');

        self::assertSame('advertiserName', $columns[0]->column);
        self::assertSame($sort, $columns[0]->sort);
        $leads = self::leads();
        self::assertSame('Acme', $columns[0]->valueOf($leads[0], static fn(): mixed => null));
        self::assertNull($columns[0]->valueOf($leads[3], static fn(): mixed => null));
    }

    #[Test]
    public function anUnresolvedThroughFilterIsRefusedByTheHandler(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has not been resolved');

        (new ArrayFilterHandler())->apply(Where::make('name')->through('owningCampaign')->build(), self::leads(), 'x');
    }

    /**
     * @return list<int>
     */
    private function ids(FilterInterface $filter, mixed $value): array
    {
        $rows = (new ArrayFilterHandler())->apply($this->resolve($filter), self::leads(), $value);
        self::assertIsArray($rows);

        return self::idsOf($rows);
    }

    /**
     * @param array<mixed> $rows
     *
     * @return list<int>
     */
    private static function idsOf(array $rows): array
    {
        return \array_values(\array_map(static fn(mixed $row): int => \is_object($row) && \is_int($row->id ?? null) ? $row->id : 0, $rows));
    }

    private function resolve(FilterInterface $filter): FilterInterface
    {
        return $this->resolver()->resolve($filter, self::types()['leads']);
    }

    private function resolver(): ThroughPathResolver
    {
        $types = self::types();

        return new ThroughPathResolver(static fn(string $type): ?array => $types[$type] ?? null);
    }

    /**
     * @return list<object>
     */
    private static function leads(): array
    {
        $acme = (object) ['id' => 10, 'company_name' => 'Acme'];
        $zenith = (object) ['id' => 11, 'company_name' => 'Zenith'];
        $welcome = (object) ['id' => 'a', 'name' => 'welcome'];
        $reminder = (object) ['id' => 'b', 'name' => 'reminder'];

        $spring = (object) ['id' => 100, 'name' => 'spring sale', 'budget' => 500, 'start_date' => new \DateTimeImmutable('2026-03-02'), 'archivedAt' => null, 'advertiser' => $acme];
        $springAgain = (object) ['id' => 101, 'name' => 'spring launch', 'budget' => 150, 'start_date' => new \DateTimeImmutable('2026-03-31'), 'archivedAt' => null, 'advertiser' => $zenith];
        $orphan = (object) ['id' => 102, 'name' => 'winter', 'budget' => 50, 'start_date' => new \DateTimeImmutable('2026-01-01'), 'archivedAt' => null, 'advertiser' => null];

        return [
            (object) ['id' => 1, 'campaign' => $spring, 'templates' => [$welcome]],
            (object) ['id' => 2, 'campaign' => $springAgain, 'templates' => [$reminder]],
            (object) ['id' => 3, 'campaign' => $orphan, 'templates' => [$welcome, $reminder]],
            (object) ['id' => 4, 'campaign' => null, 'templates' => []],
        ];
    }

    /**
     * @return array<string, list<FieldInterface>>
     */
    private static function types(): array
    {
        return [
            'leads' => [
                Id::make()->numeric()->build(),
                Date::make('startDate')->build(),
                BelongsTo::make('owningCampaign', 'campaigns')->storedAs('campaign')->build(),
                HasMany::make('templates', 'templates')->build(),
                BelongsTo::make('ghost', 'ghosts')->build(),
                MorphTo::make('subject', ['campaigns', 'templates'])->build(),
            ],
            'campaigns' => [
                Id::make()->numeric()->build(),
                Str::make('name')->build(),
                Str::make('label')->computed()->build(),
                Integer::make('budget')->build(),
                Date::make('startDate')->storedAs('start_date')->build(),
                DateTime::make('launchedAt')->build(),
                DateTime::make('archivedAt')->build(),
                BelongsTo::make('advertiser', 'advertisers')->build(),
                BelongsTo::make('brand', 'brands')->build(),
                BelongsTo::make('encodedBrand', 'encodedBrands')->storedAs('brand')->build(),
            ],
            'advertisers' => [Id::make()->numeric()->build(), Str::make('name')->storedAs('company_name')->build()],
            'templates' => [Id::make()->uuid()->build(), Str::make('name')->build()],
            'brands' => [Id::make()->build()],
            'encodedBrands' => [Id::make()->encodeUsing(new ReversingIdEncoder())->build()],
        ];
    }
}
