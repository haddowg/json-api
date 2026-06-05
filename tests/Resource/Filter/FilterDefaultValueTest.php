<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `default()` refinement on every value-carrying filter: declaring is
 * immutable and flag-tracked (`null` is a legitimate default), and the other
 * refinement helpers thread a declared default through unchanged.
 */
final class FilterDefaultValueTest extends TestCase
{
    /**
     * @return iterable<string, array{Where|WhereIn|WhereNotIn|WhereIdIn|WhereIdNotIn}>
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
    public function aFilterHasNoDefaultUntilDeclared(Where|WhereIn|WhereNotIn|WhereIdIn|WhereIdNotIn $filter): void
    {
        self::assertFalse($filter->hasDefault());
        self::assertNull($filter->defaultValue());
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function declaringADefaultIsImmutableAndFlagTracked(Where|WhereIn|WhereNotIn|WhereIdIn|WhereIdNotIn $filter): void
    {
        $defaulted = $filter->default('value');

        self::assertNotSame($filter, $defaulted);
        self::assertTrue($defaulted->hasDefault());
        self::assertSame('value', $defaulted->defaultValue());

        // The original is untouched, and null is a declarable default.
        self::assertFalse($filter->hasDefault());

        $nullDefault = $filter->default(null);
        self::assertTrue($nullDefault->hasDefault());
        self::assertNull($nullDefault->defaultValue());
    }

    #[Test]
    public function theOtherRefinementHelpersThreadTheDefaultThrough(): void
    {
        $where = Where::make('status')->default('active')
            ->singular()
            ->deserializeUsing(static fn(mixed $value): mixed => $value);
        self::assertTrue($where->hasDefault());
        self::assertSame('active', $where->defaultValue());
        self::assertTrue($where->singular);

        $whereIn = WhereIn::make('tags')->default('a,b')
            ->delimiter('|')
            ->singular();
        self::assertTrue($whereIn->hasDefault());
        self::assertSame('a,b', $whereIn->defaultValue());
        self::assertSame('|', $whereIn->delimiter);

        $whereNotIn = WhereNotIn::make('tags')->default(['a'])
            ->delimiter('|')
            ->singular();
        self::assertTrue($whereNotIn->hasDefault());
        self::assertSame(['a'], $whereNotIn->defaultValue());

        $whereIdIn = WhereIdIn::make()->default('1,2')->delimiter('|');
        self::assertTrue($whereIdIn->hasDefault());
        self::assertSame('1,2', $whereIdIn->defaultValue());

        $whereIdNotIn = WhereIdNotIn::make()->default('3')->delimiter('|');
        self::assertTrue($whereIdNotIn->hasDefault());
        self::assertSame('3', $whereIdNotIn->defaultValue());
    }

    #[Test]
    public function declaringADefaultPreservesTheOtherRefinements(): void
    {
        $where = Where::make('status')->singular()->default('active');
        self::assertTrue($where->singular);
        self::assertTrue($where->hasDefault());

        $whereIn = WhereIn::make('tags')->delimiter('|')->default('a|b');
        self::assertSame('|', $whereIn->delimiter);
        self::assertTrue($whereIn->hasDefault());
    }
}
