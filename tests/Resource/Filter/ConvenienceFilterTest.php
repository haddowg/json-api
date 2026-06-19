<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Filter\Boolean;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\EndsWith;
use haddowg\JsonApi\Resource\Filter\FixedOperator;
use haddowg\JsonApi\Resource\Filter\GreaterThan;
use haddowg\JsonApi\Resource\Filter\GreaterThanOrEqual;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\LessThan;
use haddowg\JsonApi\Resource\Filter\LessThanOrEqual;
use haddowg\JsonApi\Resource\Filter\Numeric;
use haddowg\JsonApi\Resource\Filter\NumericCoercion;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\StartsWith;
use haddowg\JsonApi\Resource\Filter\Where;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayFilterHandler::class)]
#[CoversClass(Where::class)]
#[CoversClass(Contains::class)]
#[CoversClass(StartsWith::class)]
#[CoversClass(EndsWith::class)]
#[CoversClass(Numeric::class)]
#[CoversClass(GreaterThan::class)]
#[CoversClass(GreaterThanOrEqual::class)]
#[CoversClass(LessThan::class)]
#[CoversClass(LessThanOrEqual::class)]
#[CoversClass(Boolean::class)]
#[CoversClass(FixedOperator::class)]
#[CoversClass(Range::class)]
#[CoversClass(DateRange::class)]
#[CoversClass(NumericCoercion::class)]
#[Group('spec:filtering')]
final class ConvenienceFilterTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function people(): array
    {
        return [
            ['id' => '1', 'name' => 'Ada Lovelace', 'age' => 5, 'active' => true, 'email' => 'ada@example.io'],
            ['id' => '2', 'name' => 'Alan Turing', 'age' => 18, 'active' => false, 'email' => 'alan@example.com'],
            ['id' => '3', 'name' => 'Grace Hopper', 'age' => 50, 'active' => true, 'email' => 'grace@example.io'],
        ];
    }

    /**
     * @return list<string>
     */
    private function ids(mixed $result): array
    {
        self::assertIsArray($result);
        $ids = [];
        foreach ($result as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['id']);
            $ids[] = $row['id'];
        }

        return $ids;
    }

    #[Test]
    public function containsIsCaseInsensitiveSubstring(): void
    {
        $result = (new ArrayFilterHandler())->apply(Contains::make('name'), $this->people(), 'LA');

        // "Ada Lovelace" (Love**la**ce), "A**la**n Turing" — both contain "la"
        // folded; "Grace Hopper" does not.
        self::assertSame(['1', '2'], $this->ids($result));
    }

    #[Test]
    public function startsWithMatchesPrefixCaseInsensitively(): void
    {
        $result = (new ArrayFilterHandler())->apply(StartsWith::make('name'), $this->people(), 'a');

        self::assertSame(['1', '2'], $this->ids($result));
    }

    #[Test]
    public function startsWithDoesNotMatchMidString(): void
    {
        // "lan" is inside "Alan" but the name does not start with it.
        $result = (new ArrayFilterHandler())->apply(StartsWith::make('name'), $this->people(), 'lan');

        self::assertSame([], $this->ids($result));
    }

    #[Test]
    public function endsWithMatchesSuffixCaseInsensitively(): void
    {
        $result = (new ArrayFilterHandler())->apply(EndsWith::make('email'), $this->people(), '.IO');

        self::assertSame(['1', '3'], $this->ids($result));
    }

    #[Test]
    public function endsWithDoesNotMatchMidString(): void
    {
        $result = (new ArrayFilterHandler())->apply(EndsWith::make('email'), $this->people(), 'example');

        self::assertSame([], $this->ids($result));
    }

    #[Test]
    public function numericEqualityCoercesTheValue(): void
    {
        $result = (new ArrayFilterHandler())->apply(Numeric::make('age'), $this->people(), '18');

        self::assertSame(['2'], $this->ids($result));
    }

    #[Test]
    public function greaterThanComparesNumerically(): void
    {
        // 18 and 50 are > 6; 5 is not.
        $result = (new ArrayFilterHandler())->apply(GreaterThan::make('age'), $this->people(), '6');

        self::assertSame(['2', '3'], $this->ids($result));
    }

    #[Test]
    public function greaterThanOrEqualIsInclusive(): void
    {
        $result = (new ArrayFilterHandler())->apply(GreaterThanOrEqual::make('age'), $this->people(), '18');

        self::assertSame(['2', '3'], $this->ids($result));
    }

    #[Test]
    public function lessThanComparesNumerically(): void
    {
        $result = (new ArrayFilterHandler())->apply(LessThan::make('age'), $this->people(), '18');

        self::assertSame(['1'], $this->ids($result));
    }

    #[Test]
    public function lessThanOrEqualIsInclusive(): void
    {
        $result = (new ArrayFilterHandler())->apply(LessThanOrEqual::make('age'), $this->people(), '18');

        self::assertSame(['1', '2'], $this->ids($result));
    }

    #[Test]
    public function greaterThanCoercesFloats(): void
    {
        $data = [
            ['id' => '1', 'price' => 9.5],
            ['id' => '2', 'price' => 10.5],
        ];

        $result = (new ArrayFilterHandler())->apply(GreaterThan::make('price'), $data, '10.0');

        self::assertSame(['2'], $this->ids($result));
    }

    /**
     * The numeric footgun fix, PHP-8-honest (see the report's flagged finding).
     * PHP 8 already compares two *clean* numeric strings numerically, so the
     * value the convenience guarantees is the **type fidelity** the comparison and
     * the downstream data layer rely on: `GreaterThan` coerces the incoming
     * `'18'` to a real `int 18`, whereas a bare `Where` passes the raw `string`
     * `'18'` straight through to the comparison (and onward to the SQL bind). This
     * is the value Slice 2's push-down predicates depend on, and the spec's
     * "compares numerically, not as a string" guarantee at the value level. The
     * end-to-end query result is asserted in the other cases above.
     */
    #[Test]
    public function greaterThanCoercesTheValueToANumberWhereasABareWherePassesAString(): void
    {
        $handler = new ArrayFilterHandler();
        $data = [['id' => '1', 'age' => 18]];

        // Capture the value the comparison actually sees by re-wrapping the
        // convenience's preset deserializer behind a spy.
        $convenience = GreaterThan::make('age');
        self::assertNotNull($convenience->deserialize);
        // assertSame is type-strict: it proves both the int value and the int type.
        self::assertSame(18, ($convenience->deserialize)('18'));
        self::assertSame(18.5, ($convenience->deserialize)('18.5'));

        // A bare Where has no deserializer — the raw string flows to comparison.
        $bare = Where::make('age', operator: '>');
        self::assertNull($bare->deserialize);

        // Both still keep the same clean-numeric row in PHP 8 (numeric strings
        // compare numerically) — the difference is the *type* that reaches the
        // comparison and the data layer.
        self::assertSame(['1'], $this->ids($handler->apply($convenience, $data, '6')));
        self::assertSame(['1'], $this->ids($handler->apply($bare, $data, '6')));
    }

    /**
     * `DateRange`'s `\DateTimeImmutable` coercion is a genuine, severe footgun fix
     * even in PHP 8: two ISO-8601 instants written with **different UTC offsets**
     * are the same moment but compare unequal **lexically** — a bare string range
     * wrongly excludes the boundary. The temporal coercion compares the instants.
     */
    #[Test]
    public function dateRangeFixesTheLexicalDateCompareFootgun(): void
    {
        // 12:00+00:00 and 13:00+01:00 are the SAME instant.
        $data = [['id' => '1', 'at' => '2021-06-15T12:00:00+00:00']];

        // A bare string Range (numeric preset, but the value is a date string):
        // lexically "...T12:..." >= "...T13:..." is false, so the boundary row is
        // wrongly dropped.
        $bare = (new ArrayFilterHandler())->apply(
            Range::make('at')->deserializeUsing(static fn(mixed $v): mixed => $v),
            $data,
            ['min' => '2021-06-15T13:00:00+01:00'],
        );
        self::assertSame([], $this->ids($bare));

        // DateRange coerces both to \DateTimeImmutable: equal instants, so the
        // inclusive `>=` keeps the boundary row.
        $coerced = (new ArrayFilterHandler())->apply(
            DateRange::make('at'),
            $data,
            ['min' => '2021-06-15T13:00:00+01:00'],
        );
        self::assertSame(['1'], $this->ids($coerced));
    }

    #[Test]
    public function booleanCoercesTruthyStrings(): void
    {
        foreach (['1', 'true', 'on', 'yes'] as $truthy) {
            $result = (new ArrayFilterHandler())->apply(Boolean::make('active'), $this->people(), $truthy);
            self::assertSame(['1', '3'], $this->ids($result), "value: {$truthy}");
        }
    }

    #[Test]
    public function booleanMatchesFalse(): void
    {
        foreach (['0', 'false', 'off', 'no'] as $falsy) {
            $result = (new ArrayFilterHandler())->apply(Boolean::make('active'), $this->people(), $falsy);
            self::assertSame(['2'], $this->ids($result), "value: {$falsy}");
        }
    }

    #[Test]
    public function booleanFilterReadsABackingColumn(): void
    {
        $data = [
            ['id' => '1', 'is_active' => true],
            ['id' => '2', 'is_active' => false],
        ];

        $result = (new ArrayFilterHandler())->apply(Boolean::make('active', 'is_active'), $data, 'true');

        self::assertSame(['1'], $this->ids($result));
    }

    /**
     * The Boolean filter's coercion and its declared value constraint must accept
     * the **same** wire vocabulary — otherwise a value the coercer happily turns
     * into a boolean (e.g. `yes`/`on`/`TRUE`) would be rejected by a
     * constraint-validating adapter (a `400`) before the filter ever ran. This
     * pins the two halves together so they cannot drift.
     */
    #[Test]
    public function everyValueBooleanCoercesAlsoPassesItsDeclaredConstraint(): void
    {
        $filter = Boolean::make('active');
        $constraint = $filter->constraints()[0];
        self::assertInstanceOf(Pattern::class, $constraint);
        $pattern = '~' . $constraint->regex . '~';

        self::assertNotNull($filter->deserialize);

        // The full FILTER_VALIDATE_BOOLEAN vocabulary (case-insensitive, the empty
        // string, surrounding whitespace) — each is coerced AND passes the constraint.
        $truthy = ['1', 'true', 'on', 'yes', 'TRUE', 'On', 'YES', ' true ', 'Yes'];
        $falsy = ['0', 'false', 'off', 'no', 'FALSE', 'Off', 'NO', ''];

        foreach ($truthy as $value) {
            self::assertTrue(($filter->deserialize)($value), "coerce truthy: {$value}");
            self::assertSame(1, \preg_match($pattern, $value), "constraint truthy: {$value}");
        }
        foreach ($falsy as $value) {
            self::assertFalse(($filter->deserialize)($value), "coerce falsy: {$value}");
            self::assertSame(1, \preg_match($pattern, $value), "constraint falsy: {$value}");
        }

        // A value outside the vocabulary fails the constraint (so an adapter 400s it).
        foreach (['maybe', '2', 'ye'] as $value) {
            self::assertSame(0, \preg_match($pattern, $value), "constraint rejects: {$value}");
        }
    }

    /**
     * A scalar convenience's operator is its identity. The `$operator` argument
     * exists only for {@see Where::make()} signature parity; passing a different
     * operator is a loud error, never a silently-ignored value.
     */
    #[Test]
    public function aConvenienceRejectsAnAttemptToOverrideItsFixedOperator(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GreaterThan::make('age', null, '<');
    }

    #[Test]
    public function aConveniencePassedItsOwnIdentityOperatorIsAccepted(): void
    {
        // Passing the identity operator explicitly is harmless (no throw), and the
        // resulting filter still carries the fixed operator.
        self::assertSame('like', Contains::make('name', null, 'like')->operator);
        self::assertSame('=', Boolean::make('active', null, '=')->operator);
        self::assertSame('>=', GreaterThanOrEqual::make('age', null, '>=')->operator);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function products(): array
    {
        return [
            ['id' => '1', 'price' => 5],
            ['id' => '2', 'price' => 50],
            ['id' => '3', 'price' => 100],
            ['id' => '4', 'price' => 150],
        ];
    }

    #[Test]
    public function rangeAppliesBothBounds(): void
    {
        $result = (new ArrayFilterHandler())->apply(Range::make('price'), $this->products(), ['min' => '10', 'max' => '100']);

        self::assertSame(['2', '3'], $this->ids($result));
    }

    #[Test]
    public function rangeWithOnlyTheMinBoundIsOpenEnded(): void
    {
        $result = (new ArrayFilterHandler())->apply(Range::make('price'), $this->products(), ['min' => '50']);

        self::assertSame(['2', '3', '4'], $this->ids($result));
    }

    #[Test]
    public function rangeWithOnlyTheMaxBoundIsOpenEnded(): void
    {
        $result = (new ArrayFilterHandler())->apply(Range::make('price'), $this->products(), ['max' => '50']);

        self::assertSame(['1', '2'], $this->ids($result));
    }

    #[Test]
    public function rangeTreatsABlankBoundAsAbsent(): void
    {
        $result = (new ArrayFilterHandler())->apply(Range::make('price'), $this->products(), ['min' => '50', 'max' => '']);

        self::assertSame(['2', '3', '4'], $this->ids($result));
    }

    #[Test]
    public function rangeWithAnEntirelyAbsentValueIsANoOp(): void
    {
        // An empty array (no bounds) keeps every row.
        $result = (new ArrayFilterHandler())->apply(Range::make('price'), $this->products(), []);

        self::assertSame(['1', '2', '3', '4'], $this->ids($result));
    }

    #[Test]
    public function rangeComparesNumericallyNotLexically(): void
    {
        // String column "100" must be kept by min=20 — lexically '100' < '20'.
        $data = [
            ['id' => '1', 'price' => '5'],
            ['id' => '2', 'price' => '100'],
        ];

        $result = (new ArrayFilterHandler())->apply(Range::make('price'), $data, ['min' => '20']);

        self::assertSame(['2'], $this->ids($result));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function posts(): array
    {
        return [
            ['id' => '1', 'published' => '2020-01-01T00:00:00+00:00'],
            ['id' => '2', 'published' => '2021-06-15T12:00:00+00:00'],
            ['id' => '3', 'published' => '2022-12-31T23:59:59+00:00'],
        ];
    }

    #[Test]
    public function dateRangeAppliesBothBoundsTemporally(): void
    {
        $filter = DateRange::make('published', 'published');
        $result = (new ArrayFilterHandler())->apply($filter, $this->posts(), [
            'min' => '2021-01-01T00:00:00+00:00',
            'max' => '2022-01-01T00:00:00+00:00',
        ]);

        self::assertSame(['2'], $this->ids($result));
    }

    #[Test]
    public function dateRangeWithOnlyTheMinBoundIsOpenEnded(): void
    {
        $filter = DateRange::make('published');
        $result = (new ArrayFilterHandler())->apply($filter, $this->posts(), ['min' => '2021-01-01T00:00:00+00:00']);

        self::assertSame(['2', '3'], $this->ids($result));
    }

    #[Test]
    public function dateRangeWithAnAbsentValueIsANoOp(): void
    {
        $filter = DateRange::make('published');
        $result = (new ArrayFilterHandler())->apply($filter, $this->posts(), []);

        self::assertSame(['1', '2', '3'], $this->ids($result));
    }

    #[Test]
    public function dateRangeBacksAStoredColumn(): void
    {
        // wire key differs from the backing column.
        $data = [
            ['id' => '1', 'published_at' => '2020-01-01T00:00:00+00:00'],
            ['id' => '2', 'published_at' => '2022-01-01T00:00:00+00:00'],
        ];
        $filter = DateRange::make('published', 'published_at');
        self::assertSame('published', $filter->key());

        $result = (new ArrayFilterHandler())->apply($filter, $data, ['min' => '2021-01-01T00:00:00+00:00']);

        self::assertSame(['2'], $this->ids($result));
    }

    #[Test]
    public function dateRangeSkipsACalendarInvalidBoundRatherThanComparingLexically(): void
    {
        // `1997-13-99` (month 13, day 99) passes the lenient ISO-8601 shape Pattern
        // but does not parse, so toDateTime() does not coerce it to a
        // \DateTimeImmutable. A framework adapter rejects such a bound as a clean 400
        // BEFORE the provider, but the handler must not, in its absence, compare a
        // \DateTimeImmutable column against the raw string — PHP would silently make
        // that a lexical string compare and keep EVERY row (a divergence from a
        // database adapter binding a non-date string). Instead the bound is skipped
        // (treated as open/absent), so a min-only calendar-invalid value is a no-op,
        // not a full-set match.
        $filter = DateRange::make('published');
        $result = (new ArrayFilterHandler())->apply($filter, $this->posts(), ['min' => '1997-13-99']);

        self::assertSame(['1', '2', '3'], $this->ids($result));

        // The mirror case: a max-only calendar-invalid bound is likewise a no-op (not
        // the empty set a lexical `\DateTimeImmutable <= '1997-13-99'` would give).
        $result = (new ArrayFilterHandler())->apply($filter, $this->posts(), ['max' => '1997-13-99']);

        self::assertSame(['1', '2', '3'], $this->ids($result));
    }

    #[Test]
    public function eachScalarConveniencePresetsItsValueConstraint(): void
    {
        // The numeric conveniences preset a numeric Pattern so the OpenAPI
        // generator (which reads constraints()) narrows the value schema.
        foreach ([Numeric::make('age'), GreaterThan::make('age'), GreaterThanOrEqual::make('age'), LessThan::make('age'), LessThanOrEqual::make('age')] as $filter) {
            self::assertCount(1, $filter->constraints());
            self::assertInstanceOf(Pattern::class, $filter->constraints()[0]);
        }

        // Boolean presets a boolean Pattern.
        self::assertCount(1, Boolean::make('active')->constraints());
        self::assertInstanceOf(Pattern::class, Boolean::make('active')->constraints()[0]);

        // String conveniences keep a permissive string value (no constraint).
        self::assertSame([], Contains::make('name')->constraints());
        self::assertSame([], StartsWith::make('name')->constraints());
        self::assertSame([], EndsWith::make('name')->constraints());
    }

    #[Test]
    public function rangePresetsItsNumericConstraint(): void
    {
        $filter = Range::make('price');

        self::assertCount(1, $filter->constraints());
        self::assertInstanceOf(Pattern::class, $filter->constraints()[0]);
    }

    #[Test]
    public function dateRangePresetsAnIso8601BoundConstraint(): void
    {
        // DateRange presets an ISO-8601 Pattern (not the numeric one) so a framework
        // validator rejects a malformed bound before the filter reaches the data layer.
        $filter = DateRange::make('published');

        self::assertCount(1, $filter->constraints());
        $constraint = $filter->constraints()[0];
        self::assertInstanceOf(Pattern::class, $constraint);

        // The preset pattern accepts a bare date and a zoned date-time, rejects junk.
        $regex = '/' . $constraint->regex . '/';
        self::assertSame(1, \preg_match($regex, '1995-01-01'));
        self::assertSame(1, \preg_match($regex, '1997-05-21T12:30:00Z'));
        self::assertSame(1, \preg_match($regex, '1997-05-21T12:30:00+01:00'));
        self::assertSame(0, \preg_match($regex, 'banana'));
        self::assertSame(0, \preg_match($regex, '21/05/1997'));
    }

    #[Test]
    public function eachConveniencePresetsADescription(): void
    {
        self::assertNotNull(Contains::make('name')->getDescription());
        self::assertNotNull(StartsWith::make('name')->getDescription());
        self::assertNotNull(EndsWith::make('name')->getDescription());
        self::assertNotNull(Numeric::make('age')->getDescription());
        self::assertNotNull(GreaterThan::make('age')->getDescription());
        self::assertNotNull(Boolean::make('active')->getDescription());
        self::assertNotNull(Range::make('price')->getDescription());
        self::assertNotNull(DateRange::make('published')->getDescription());
    }

    #[Test]
    public function aSubclassKeepsItsIdentityAndPresetsAcrossAFluentRefinement(): void
    {
        // `new static(...)` in Where's withers preserves the subclass type, so a
        // `->describedAs()`/`->default()` chain does not downcast to a bare Where
        // and the preset operator/deserializer/constraint survive.
        $filter = GreaterThan::make('age')->describedAs('Custom')->default('0');

        self::assertInstanceOf(GreaterThan::class, $filter);
        self::assertSame('Custom', $filter->getDescription());
        self::assertSame('>', $filter->operator);
        self::assertCount(1, $filter->constraints());

        // The coercion still applies after the refinement.
        $result = (new ArrayFilterHandler())->apply($filter, [['id' => '1', 'age' => '5'], ['id' => '2', 'age' => '18']], '6');
        self::assertSame(['2'], $this->ids($result));
    }
}
