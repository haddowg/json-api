<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Filter\BuildsEnumValue;
use haddowg\JsonApi\Resource\Filter\FilterFamily;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A value filter declared with `enum()` constrains its value, or each member of a list,
 * to the backing values of a backed enum's cases, and still compares the backing value.
 */
#[CoversTrait(BuildsEnumValue::class)]
final class FilterEnumTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): FilterInterface}>
     */
    public static function builders(): iterable
    {
        yield 'Where' => [static fn(): FilterInterface => Where::make('state')->enum(FilterEnumState::class)->build()];
        yield 'WhereIn' => [static fn(): FilterInterface => WhereIn::make('state')->enum(FilterEnumState::class)->build()];
        yield 'WhereNotIn' => [static fn(): FilterInterface => WhereNotIn::make('state')->enum(FilterEnumState::class)->build()];
        yield 'WhereThrough' => [static fn(): FilterInterface => WhereThrough::make('owner.state')->enum(FilterEnumState::class)->build()];
        yield 'WhereThroughIn' => [static fn(): FilterInterface => WhereThroughIn::make('owner.state')->enum(FilterEnumState::class)->build()];
        yield 'a filter family member' => [static function (): FilterInterface {
            $member = FilterFamily::make('state', static fn(string $suffix): FilterInterface => Where::make('state.' . $suffix, $suffix)->enum(FilterEnumState::class)->build())
                ->resolve('current', new StubJsonApiRequest());
            self::assertNotNull($member);

            return $member;
        }];
    }

    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function pureEnumDeclarations(): iterable
    {
        // @phpstan-ignore argument.type (a pure enum is what is refused)
        yield 'Where' => [static fn(): mixed => Where::make('state')->enum(FilterEnumPure::class)];
        // @phpstan-ignore argument.type (a pure enum is what is refused)
        yield 'WhereIn' => [static fn(): mixed => WhereIn::make('state')->enum(FilterEnumPure::class)];
        // @phpstan-ignore argument.type (a pure enum is what is refused)
        yield 'WhereNotIn' => [static fn(): mixed => WhereNotIn::make('state')->enum(FilterEnumPure::class)];
        // @phpstan-ignore argument.type (a pure enum is what is refused)
        yield 'WhereThrough' => [static fn(): mixed => WhereThrough::make('owner.state')->enum(FilterEnumPure::class)];
        // @phpstan-ignore argument.type (a pure enum is what is refused)
        yield 'WhereThroughIn' => [static fn(): mixed => WhereThroughIn::make('owner.state')->enum(FilterEnumPure::class)];
    }

    /**
     * @param \Closure(): FilterInterface $build
     */
    #[Test]
    #[DataProvider('builders')]
    public function enumConstrainsTheValueToTheBackingValues(\Closure $build): void
    {
        self::assertEquals([new In(['running', 'cancelled'], enumClass: FilterEnumState::class)], $build()->constraints());
    }

    /**
     * @param \Closure(): mixed $declare
     */
    #[Test]
    #[DataProvider('pureEnumDeclarations')]
    public function enumRefusesAPureEnum(\Closure $declare): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('enum() takes a backed enum');

        $declare();
    }

    #[Test]
    public function enumAppendsToTheOtherConstraints(): void
    {
        $filter = Where::make('state')->pattern('^[a-z]+$')->enum(FilterEnumState::class)->build();

        self::assertCount(2, $filter->constraints());
        self::assertInstanceOf(In::class, $filter->constraints()[1]);
    }

    #[Test]
    public function theFilterStillComparesTheBackingValue(): void
    {
        $rows = [['id' => 1, 'state' => 'running'], ['id' => 2, 'state' => 'cancelled']];
        $handler = new ArrayFilterHandler();

        self::assertSame([2], \array_column(\array_values($handler->apply(Where::make('state')->enum(FilterEnumState::class)->build(), $rows, 'cancelled')), 'id'));
        self::assertSame([1], \array_column(\array_values($handler->apply(WhereIn::make('state')->enum(FilterEnumState::class)->build(), $rows, ['running'])), 'id'));
    }
}

enum FilterEnumState: string
{
    case Running = 'running';
    case Cancelled = 'cancelled';
}

enum FilterEnumPure
{
    case Only;
}
