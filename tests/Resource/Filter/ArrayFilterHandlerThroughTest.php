<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\FilterMatcher;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApi\Tests\Double\ReversingIdEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The reference traversal of a path that ends on a relationship, and of a list at the
 * leaf, which the database adapters are held to.
 */
#[CoversClass(ArrayFilterHandler::class)]
#[CoversClass(WhereThroughIn::class)]
#[CoversClass(WhereThrough::class)]
#[Group('spec:filtering')]
final class ArrayFilterHandlerThroughTest extends TestCase
{
    #[Test]
    public function aListMatchesAnyRelatedIdThroughAToOneChain(): void
    {
        self::assertSame(['1', '3'], $this->apply(WhereThroughIn::make('advertiser', 'campaign.advertiser'), '4,9'));
        self::assertSame(['2'], $this->apply(WhereThroughIn::make('advertiser', 'campaign.advertiser'), ['7']));
    }

    #[Test]
    public function aListMatchesAnyMemberOfAToManyRelation(): void
    {
        self::assertSame(['1', '2'], $this->apply(WhereThroughIn::make('template', 'templates'), 't2,t3'));
        self::assertSame([], $this->apply(WhereThroughIn::make('template', 'templates'), 'missing'));
    }

    #[Test]
    public function anEmptyListMatchesNothing(): void
    {
        self::assertSame([], $this->apply(WhereThroughIn::make('template', 'templates'), []));
        self::assertSame([], $this->apply(WhereThroughIn::make('name', 'campaign.name'), []));
    }

    #[Test]
    public function aListAtAFieldLeafMatchesTheFieldsValue(): void
    {
        self::assertSame(['1', '2'], $this->apply(WhereThroughIn::make('name', 'campaign.name'), 'Spring,Summer'));
    }

    #[Test]
    public function anEncodedIdIsDecodedBeforeComparing(): void
    {
        // Brand storage keys are 12 and 34; the wire ids are their reverse.
        self::assertSame(['1'], $this->apply(WhereThroughIn::make('brand'), '21'));
        self::assertSame(['1', '2'], $this->apply(WhereThroughIn::make('brand'), '21,43'));
        self::assertSame(['2'], $this->apply(WhereThrough::make('brand'), '43'));
    }

    #[Test]
    public function anUndecodableIdIsAFilterValueError(): void
    {
        $this->expectException(FilterValueInvalid::class);

        $this->apply(WhereThroughIn::make('brand'), ['21', '']);
    }

    #[Test]
    public function aWhereThroughComparesTheRelatedIdByEquality(): void
    {
        self::assertSame(['1', '3'], $this->apply(WhereThrough::make('advertiser', 'campaign.advertiser'), '4'));
        self::assertSame(['2'], $this->apply(WhereThrough::make('advertiser', 'campaign.advertiser')->operator('!='), '4'));
        self::assertSame(['1'], $this->apply(WhereThrough::make('template', 'templates'), 't1'));
    }

    #[Test]
    public function anUnresolvedPathStillComparesAField(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereThroughIn::make('name', 'campaign.name')->build(), $this->rows(), 'Summer');

        self::assertSame(['2'], self::ids($result));
    }

    #[Test]
    public function bothListFormsReachTheLeafThroughTheMatcher(): void
    {
        $filters = [WhereThroughIn::make('advertiser', 'campaign.advertiser')->build()];

        foreach ([['advertiser' => '4,7'], ['advertiser' => ['4', '7']]] as $requested) {
            $rows = $this->rows();
            foreach (FilterMatcher::directives($requested, $filters) as $directive) {
                self::assertSame(['4', '7'], $directive->value);
                $rows = (new ArrayFilterHandler())->apply($this->resolve($directive->filter), $rows, $directive->value);
            }
            self::assertSame(['1', '2', '3'], self::ids($rows));
        }
    }

    #[Test]
    public function anArrayOnlyListRefusesADelimitedString(): void
    {
        $this->expectException(FilterValueInvalid::class);

        FilterMatcher::directives(['advertiser' => '4,7'], [WhereThroughIn::make('advertiser', 'campaign.advertiser')->acceptsList(delimited: false)->build()]);
    }

    /**
     * @return list<string>
     */
    private function apply(WhereThroughIn|\haddowg\JsonApi\Resource\Filter\WhereThroughInBuilder|WhereThrough|\haddowg\JsonApi\Resource\Filter\WhereThroughBuilder $filter, mixed $value): array
    {
        $built = $filter instanceof WhereThroughIn || $filter instanceof WhereThrough ? $filter : $filter->build();

        return self::ids((new ArrayFilterHandler())->apply($this->resolve($built), $this->rows(), $value));
    }

    private function resolve(FilterInterface $filter): FilterInterface
    {
        $types = [
            'leads' => [
                Id::make()->build(),
                BelongsTo::make('campaign', 'campaigns')->build(),
                HasMany::make('templates', 'templates')->build(),
                BelongsTo::make('brand', 'brands')->build(),
            ],
            'campaigns' => [Id::make()->build(), Str::make('name')->build(), BelongsTo::make('advertiser', 'advertisers')->build()],
            'advertisers' => [Id::make()->numeric()->build()],
            'templates' => [Id::make()->build()],
            'brands' => [Id::make()->storedAs('key')->encodeUsing(new ReversingIdEncoder())->build()],
        ];
        return (new ThroughPathResolver(static fn(string $type): ?array => $types[$type] ?? null))->resolve($filter, $types['leads']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return [
            [
                'id' => '1',
                'campaign' => ['id' => 'c1', 'name' => 'Spring', 'advertiser' => ['id' => 4]],
                'templates' => [['id' => 't1'], ['id' => 't2']],
                'brand' => ['key' => '12'],
            ],
            [
                'id' => '2',
                'campaign' => ['id' => 'c2', 'name' => 'Summer', 'advertiser' => ['id' => 7]],
                'templates' => [['id' => 't3']],
                'brand' => ['key' => '34'],
            ],
            [
                'id' => '3',
                'campaign' => ['id' => 'c3', 'name' => 'Autumn', 'advertiser' => ['id' => 4]],
                'templates' => [],
                'brand' => null,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function ids(mixed $rows): array
    {
        self::assertIsArray($rows);
        $ids = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['id']);
            $ids[] = $row['id'];
        }

        return $ids;
    }
}
