<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The declared **value constraints** on a filter: a filter exposes them via
 * `constraints()` (default `[]`), the `constrain()` wither and the type shortcuts
 * append the matching core constraint immutably (mirroring the `Id` field's
 * `uuid()` / `numeric()` / `pattern()`), and a presence-only filter has none.
 */
final class FilterValueConstraintsTest extends TestCase
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
    public function aFilterHasNoConstraintsUntilDeclared(Where|WhereIn|WhereNotIn|WhereIdIn|WhereIdNotIn $filter): void
    {
        self::assertSame([], $filter->constraints());
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function constrainAppendsImmutably(Where|WhereIn|WhereNotIn|WhereIdIn|WhereIdNotIn $filter): void
    {
        $constraint = new Pattern('^x$');

        $constrained = $filter->constrain($constraint);

        self::assertNotSame($filter, $constrained);
        self::assertSame([], $filter->constraints());
        self::assertSame([$constraint], $constrained->constraints());
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function constrainAppendsToTheExistingList(Where|WhereIn|WhereNotIn|WhereIdIn|WhereIdNotIn $filter): void
    {
        $first = new Pattern('^a$');
        $second = new Pattern('^b$');

        $constrained = $filter->constrain($first)->constrain($second);

        self::assertCount(2, $constrained->constraints());
        self::assertSame([$first, $second], $constrained->constraints());
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function numericShortcutAppendsAnIntegerOrDecimalPattern(Where|WhereIn|WhereNotIn|WhereIdIn|WhereIdNotIn $filter): void
    {
        $constraints = $filter->numeric()->constraints();

        self::assertCount(1, $constraints);
        self::assertInstanceOf(Pattern::class, $constraints[0]);
        self::assertSame('^-?[0-9]+(?:\.[0-9]+)?$', $constraints[0]->regex);
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function integerShortcutAppendsAnIntegerPattern(Where|WhereIn|WhereNotIn|WhereIdIn|WhereIdNotIn $filter): void
    {
        $constraints = $filter->integer()->constraints();

        self::assertCount(1, $constraints);
        self::assertInstanceOf(Pattern::class, $constraints[0]);
        self::assertSame('^-?[0-9]+$', $constraints[0]->regex);
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function uuidShortcutAppendsAUuidFormatConstraint(Where|WhereIn|WhereNotIn|WhereIdIn|WhereIdNotIn $filter): void
    {
        $constraints = $filter->uuid()->constraints();

        self::assertCount(1, $constraints);
        self::assertInstanceOf(UuidFormat::class, $constraints[0]);
        self::assertNull($constraints[0]->version);
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function uuidShortcutCarriesTheRequestedVersion(Where|WhereIn|WhereNotIn|WhereIdIn|WhereIdNotIn $filter): void
    {
        $constraints = $filter->uuid(4)->constraints();

        self::assertInstanceOf(UuidFormat::class, $constraints[0]);
        self::assertSame(4, $constraints[0]->version);
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function booleanShortcutAppendsATrueFalseOneZeroPattern(Where|WhereIn|WhereNotIn|WhereIdIn|WhereIdNotIn $filter): void
    {
        $constraints = $filter->boolean()->constraints();

        self::assertCount(1, $constraints);
        self::assertInstanceOf(Pattern::class, $constraints[0]);
        self::assertSame('^(?:true|false|1|0)$', $constraints[0]->regex);
    }

    #[Test]
    #[DataProvider('valueCarryingFilters')]
    public function patternShortcutAppendsTheGivenRegex(Where|WhereIn|WhereNotIn|WhereIdIn|WhereIdNotIn $filter): void
    {
        $constraints = $filter->pattern('^[A-Z]{2}$')->constraints();

        self::assertCount(1, $constraints);
        self::assertInstanceOf(Pattern::class, $constraints[0]);
        self::assertSame('^[A-Z]{2}$', $constraints[0]->regex);
    }

    #[Test]
    public function aDeclaredConstraintThreadsThroughOtherWithers(): void
    {
        $filter = Where::make('age')->integer()->singular()->default('1');

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
        $filter = Where::make('age')->integer();

        self::assertContainsOnlyInstancesOf(ConstraintInterface::class, $filter->constraints());
    }
}
