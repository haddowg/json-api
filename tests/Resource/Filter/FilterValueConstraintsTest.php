<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereBuilder;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdInBuilder;
use haddowg\JsonApi\Resource\Filter\WhereIdNotInBuilder;
use haddowg\JsonApi\Resource\Filter\WhereInBuilder;
use haddowg\JsonApi\Resource\Filter\WhereNotInBuilder;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The declared **value constraints** on a filter: a built filter exposes them via
 * `constraints()` (default `[]`), the builder's `constrain()` setter and the type
 * shortcuts append the matching core constraint (mirroring the `Id` field's
 * `uuid()` / `numeric()` / `pattern()`), and a presence-only filter has none.
 */
final class FilterValueConstraintsTest extends TestCase
{
    /**
     * @return iterable<string, array{WhereBuilder|WhereInBuilder|WhereNotInBuilder|WhereIdInBuilder|WhereIdNotInBuilder}>
     */
    public static function valueCarryingFilters(): iterable
    {
        yield 'Where' => [Where::make('status')];
        yield 'WhereIn' => [WhereInBuilder::make('tags')];
        yield 'WhereNotIn' => [WhereNotInBuilder::make('tags')];
        yield 'WhereIdIn' => [WhereIdInBuilder::make()];
        yield 'WhereIdNotIn' => [WhereIdNotInBuilder::make()];
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function aFilterHasNoConstraintsUntilDeclared(WhereBuilder|WhereInBuilder|WhereNotInBuilder|WhereIdInBuilder|WhereIdNotInBuilder $filter): void
    {
        self::assertSame([], $filter->build()->constraints());
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function constrainAppendsToTheBuiltFilter(WhereBuilder|WhereInBuilder|WhereNotInBuilder|WhereIdInBuilder|WhereIdNotInBuilder $filter): void
    {
        $constraint = new Pattern('^x$');

        $constrained = $filter->constrain($constraint)->build();

        self::assertSame([$constraint], $constrained->constraints());
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function constrainAppendsToTheExistingList(WhereBuilder|WhereInBuilder|WhereNotInBuilder|WhereIdInBuilder|WhereIdNotInBuilder $filter): void
    {
        $first = new Pattern('^a$');
        $second = new Pattern('^b$');

        $constrained = $filter->constrain($first)->constrain($second)->build();

        self::assertCount(2, $constrained->constraints());
        self::assertSame([$first, $second], $constrained->constraints());
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function numericShortcutAppendsAnIntegerOrDecimalPattern(WhereBuilder|WhereInBuilder|WhereNotInBuilder|WhereIdInBuilder|WhereIdNotInBuilder $filter): void
    {
        $constraints = $filter->numeric()->build()->constraints();

        self::assertCount(1, $constraints);
        self::assertInstanceOf(Pattern::class, $constraints[0]);
        self::assertSame('^-?[0-9]+(?:\.[0-9]+)?$', $constraints[0]->regex);
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function integerShortcutAppendsAnIntegerPattern(WhereBuilder|WhereInBuilder|WhereNotInBuilder|WhereIdInBuilder|WhereIdNotInBuilder $filter): void
    {
        $constraints = $filter->integer()->build()->constraints();

        self::assertCount(1, $constraints);
        self::assertInstanceOf(Pattern::class, $constraints[0]);
        self::assertSame('^-?[0-9]+$', $constraints[0]->regex);
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function uuidShortcutAppendsAUuidFormatConstraint(WhereBuilder|WhereInBuilder|WhereNotInBuilder|WhereIdInBuilder|WhereIdNotInBuilder $filter): void
    {
        $constraints = $filter->uuid()->build()->constraints();

        self::assertCount(1, $constraints);
        self::assertInstanceOf(UuidFormat::class, $constraints[0]);
        self::assertNull($constraints[0]->version);
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function uuidShortcutCarriesTheRequestedVersion(WhereBuilder|WhereInBuilder|WhereNotInBuilder|WhereIdInBuilder|WhereIdNotInBuilder $filter): void
    {
        $constraints = $filter->uuid(4)->build()->constraints();

        self::assertInstanceOf(UuidFormat::class, $constraints[0]);
        self::assertSame(4, $constraints[0]->version);
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function booleanShortcutAppendsTheFilterValidateBooleanVocabularyPattern(WhereBuilder|WhereInBuilder|WhereNotInBuilder|WhereIdInBuilder|WhereIdNotInBuilder $filter): void
    {
        $constraints = $filter->boolean()->build()->constraints();

        self::assertCount(1, $constraints);
        self::assertInstanceOf(Pattern::class, $constraints[0]);
        self::assertSame('^\s*(?i:true|false|1|0|on|off|yes|no)\s*$|^\s*$', $constraints[0]->regex);
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function patternShortcutAppendsTheGivenRegex(WhereBuilder|WhereInBuilder|WhereNotInBuilder|WhereIdInBuilder|WhereIdNotInBuilder $filter): void
    {
        $constraints = $filter->pattern('^[A-Z]{2}$')->build()->constraints();

        self::assertCount(1, $constraints);
        self::assertInstanceOf(Pattern::class, $constraints[0]);
        self::assertSame('^[A-Z]{2}$', $constraints[0]->regex);
    }

    #[Test]
    public function aDeclaredConstraintThreadsThroughOtherWithers(): void
    {
        $filter = Where::make('age')->integer()->singular()->default('1')->build();

        self::assertCount(1, $filter->constraints());
        self::assertInstanceOf(Pattern::class, $filter->constraints()[0]);
        self::assertTrue($filter->isSingular());
        self::assertTrue($filter->hasDefault());
    }

    /**
     * @return iterable<string, array{WhereNull|WhereNotNull|WhereHas|WhereDoesntHave}>
     */
    public static function presenceOnlyFilters(): iterable
    {
        yield 'WhereNull' => [WhereNull::make('deletedAt')];
        yield 'WhereNotNull' => [WhereNotNull::make('deletedAt')];
        yield 'WhereHas' => [WhereHas::make('author')];
        yield 'WhereDoesntHave' => [WhereDoesntHave::make('author')];
    }

    #[Test]
    #[DataProvider('presenceOnlyFilters')]
    public function aPresenceOnlyFilterDeclaresNoConstraints(WhereNull|WhereNotNull|WhereHas|WhereDoesntHave $filter): void
    {
        self::assertSame([], $filter->constraints());
    }

    #[Test]
    public function declaredConstraintsAreReadableThroughTheInterface(): void
    {
        $filter = Where::make('age')->integer()->build();

        self::assertContainsOnlyInstancesOf(ConstraintInterface::class, $filter->constraints());
    }
}
