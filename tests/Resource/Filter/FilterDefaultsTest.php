<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Resource\Filter\FilterDefaults;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterDefaultsTest extends TestCase
{
    #[Test]
    public function aDefaultFillsItsAbsentKey(): void
    {
        $merged = FilterDefaults::apply(
            ['title' => 'Alpha'],
            [Where::make('title'), Where::make('status')->default('active')],
        );

        self::assertSame(['title' => 'Alpha', 'status' => 'active'], $merged);
    }

    #[Test]
    public function aRequestedKeyWinsOverTheDefault(): void
    {
        $merged = FilterDefaults::apply(
            ['status' => 'archived'],
            [Where::make('status')->default('active')],
        );

        self::assertSame(['status' => 'archived'], $merged);
    }

    #[Test]
    public function presenceWinsEvenWithAnEmptyOrNullValue(): void
    {
        $declared = [Where::make('status')->default('active')];

        self::assertSame(['status' => ''], FilterDefaults::apply(['status' => ''], $declared));
        self::assertSame(['status' => null], FilterDefaults::apply(['status' => null], $declared));
    }

    #[Test]
    public function aNullDefaultIsAppliedNotSkipped(): void
    {
        $merged = FilterDefaults::apply([], [Where::make('parent')->default(null)]);

        self::assertSame(['parent' => null], $merged);
    }

    #[Test]
    public function filtersWithoutADefaultContributeNothing(): void
    {
        $merged = FilterDefaults::apply([], [
            Where::make('title'),
            WhereIn::make('tags'),
            WhereNull::make('deletedAt'),
        ]);

        self::assertSame([], $merged);
    }

    #[Test]
    public function theFirstDeclaredDefaultWinsForASharedKey(): void
    {
        $merged = FilterDefaults::apply([], [
            Where::make('status')->default('active'),
            Where::make('status')->default('archived'),
        ]);

        self::assertSame(['status' => 'active'], $merged);
    }

    #[Test]
    public function setFilterDefaultsCarryTheirRequestShape(): void
    {
        $merged = FilterDefaults::apply([], [
            WhereIn::make('tags')->default(['a', 'b']),
            WhereIn::make('categories')->default('news,guide'),
        ]);

        self::assertSame(['tags' => ['a', 'b'], 'categories' => 'news,guide'], $merged);
    }
}
