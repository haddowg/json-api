<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereBuilder;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdInBuilder;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotInBuilder;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereInBuilder;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotInBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `default()` setter on every value-carrying filter builder: declaring is
 * flag-tracked (`null` is a legitimate default), and the other builder setters
 * thread a declared default through to the built value object unchanged.
 */
final class FilterDefaultValueTest extends TestCase
{
    /**
     * @return iterable<string, array{WhereBuilder|WhereInBuilder|WhereNotInBuilder|WhereIdInBuilder|WhereIdNotInBuilder}>
     */
    public static function valueCarryingFilters(): iterable
    {
        yield 'Where' => [Where::make('status')];
        yield 'WhereIn' => [WhereIn::make('tags')];
        yield 'WhereNotIn' => [WhereNotIn::make('tags')];
        yield 'WhereIdIn' => [WhereIdIn::make()];
        yield 'WhereIdNotIn' => [WhereIdNotIn::make()];
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function aFilterHasNoDefaultUntilDeclared(WhereBuilder|WhereInBuilder|WhereNotInBuilder|WhereIdInBuilder|WhereIdNotInBuilder $filter): void
    {
        $built = $filter->build();

        self::assertFalse($built->hasDefault());
        self::assertNull($built->defaultValue());
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function declaringADefaultIsFlagTracked(WhereBuilder|WhereInBuilder|WhereNotInBuilder|WhereIdInBuilder|WhereIdNotInBuilder $filter): void
    {
        $defaulted = $filter->default('value')->build();

        self::assertTrue($defaulted->hasDefault());
        self::assertSame('value', $defaulted->defaultValue());

        // null is a declarable default (distinct from "no default").
        $nullDefault = $filter->default(null)->build();
        self::assertTrue($nullDefault->hasDefault());
        self::assertNull($nullDefault->defaultValue());
    }

    #[Test]
    public function theOtherRefinementHelpersThreadTheDefaultThrough(): void
    {
        $where = Where::make('status')->default('active')
            ->singular()
            ->deserializeUsing(static fn(mixed $value): mixed => $value)
            ->build();
        self::assertTrue($where->hasDefault());
        self::assertSame('active', $where->defaultValue());
        self::assertTrue($where->singular);

        $whereIn = WhereIn::make('tags')->default('a,b')
            ->delimiter('|')
            ->singular()
            ->build();
        self::assertTrue($whereIn->hasDefault());
        self::assertSame('a,b', $whereIn->defaultValue());
        self::assertSame('|', $whereIn->delimiter);

        $whereNotIn = WhereNotIn::make('tags')->default(['a'])
            ->delimiter('|')
            ->singular()
            ->build();
        self::assertTrue($whereNotIn->hasDefault());
        self::assertSame(['a'], $whereNotIn->defaultValue());

        $whereIdIn = WhereIdIn::make()->default('1,2')->delimiter('|')->build();
        self::assertTrue($whereIdIn->hasDefault());
        self::assertSame('1,2', $whereIdIn->defaultValue());

        $whereIdNotIn = WhereIdNotIn::make()->default('3')->delimiter('|')->build();
        self::assertTrue($whereIdNotIn->hasDefault());
        self::assertSame('3', $whereIdNotIn->defaultValue());
    }

    #[Test]
    public function declaringADefaultPreservesTheOtherRefinements(): void
    {
        $where = Where::make('status')->singular()->default('active')->build();
        self::assertTrue($where->singular);
        self::assertTrue($where->hasDefault());

        $whereIn = WhereIn::make('tags')->delimiter('|')->default('a|b')->build();
        self::assertSame('|', $whereIn->delimiter);
        self::assertTrue($whereIn->hasDefault());
    }
}
