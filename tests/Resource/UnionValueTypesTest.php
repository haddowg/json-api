<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Resource\ValueTypeMismatch;
use haddowg\JsonApi\Resource\ValueTypes;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A union's value must have one of its members' JSON types, and then whatever that member
 * requires, at the same pointer.
 */
#[CoversClass(ValueTypes::class)]
#[CoversClass(ValueTypeMismatch::class)]
#[CoversClass(Union::class)]
final class UnionValueTypesTest extends TestCase
{
    #[Test]
    public function aUnionsTypeIsItsMembersTypesJoined(): void
    {
        self::assertSame('string|number|boolean|array', ValueTypes::of($this->value()));
        self::assertSame('string|integer', ValueTypes::of(Union::make('v')->of(Integer::make(), Str::make())->build()));
    }

    #[Test]
    public function joinedTypesMatchAValueAnyOfThemMatches(): void
    {
        self::assertTrue(ValueTypes::matches('string|integer', 'a'));
        self::assertTrue(ValueTypes::matches('string|integer', 3));
        self::assertTrue(ValueTypes::matches('string|integer', 3.0));
        self::assertFalse(ValueTypes::matches('string|integer', 3.5));
        self::assertFalse(ValueTypes::matches('string|integer', true));
        self::assertFalse(ValueTypes::matches('string|integer', ['a']));
    }

    #[Test]
    public function aValueOfAMembersTypeHasNoMismatch(): void
    {
        foreach (['a', 1, 1.5, true, [], ['a', 2, false], null] as $value) {
            self::assertSame([], $this->mismatches($this->value(), $value), \json_encode($value, \JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function aTypeNoMemberTakesIsOneMismatchAtTheUnion(): void
    {
        $mismatches = $this->mismatches($this->value(), ['k' => 'v']);

        self::assertCount(1, $mismatches);
        self::assertSame('/data/attributes/value', $mismatches[0]->pointer);
        self::assertSame('string|number|boolean|array', $mismatches[0]->expected);
        self::assertSame(['string', 'number', 'boolean', 'array'], $mismatches[0]->expectedTypes());
        self::assertInstanceOf(Union::class, $mismatches[0]->field);
    }

    #[Test]
    public function aFractionIsRefusedWhenTheNumberMemberIsAnInteger(): void
    {
        $mismatches = $this->mismatches(Union::make('value')->of(Str::make(), Integer::make())->build(), 1.5);

        self::assertCount(1, $mismatches);
        self::assertSame('string|integer', $mismatches[0]->expected);
    }

    #[Test]
    public function nullPassesOnlyANullableUnion(): void
    {
        $mismatches = $this->mismatches(Union::make('value')->of(Str::make())->build(), null);

        self::assertCount(1, $mismatches);
        self::assertTrue($mismatches[0]->isNull());
    }

    #[Test]
    public function eachListElementIsCheckedAgainstTheElementUnionAtItsOwnPointer(): void
    {
        $mismatches = $this->mismatches($this->value(), ['a', ['x'], null, 2]);

        self::assertSame(['/data/attributes/value/1', '/data/attributes/value/2'], \array_map(static fn(ValueTypeMismatch $m): string => $m->pointer, $mismatches));
        self::assertSame('string|number|boolean', $mismatches[0]->expected);
        // A list never holds null in place of an element.
        self::assertTrue($mismatches[1]->isNull());
    }

    #[Test]
    public function aListMemberOfScalarsChecksItsElementType(): void
    {
        $field = Union::make('value')->of(Str::make(), ArrayList::make()->of('integer'))->build();

        $mismatches = $this->mismatches($field, [1, 'two']);

        self::assertCount(1, $mismatches);
        self::assertSame('/data/attributes/value/1', $mismatches[0]->pointer);
        self::assertSame('integer', $mismatches[0]->expected);
    }

    #[Test]
    public function aListOfUnionsAttributeChecksEachElement(): void
    {
        $field = ArrayList::make('values')->of(Union::of(Str::make(), Boolean::make()))->build();

        $found = ValueTypes::mismatchesIn([$field], ['values' => ['a', 3, true]], true, new StubJsonApiRequest());

        self::assertCount(1, $found);
        self::assertSame('/data/attributes/values/1', $found[0]->pointer);
        self::assertSame('string|boolean', $found[0]->expected);
    }

    #[Test]
    public function aUnionInsideAnObjectOrMapIsChecked(): void
    {
        $union = Union::make('cap')->of(Str::make(), Decimal::make());
        $fields = [
            Obj::make('settings')->fields($union)->build(),
            Map::make('limits')->fields(Union::make('cap')->of(Str::make(), Decimal::make()))->build(),
        ];

        $found = ValueTypes::mismatchesIn($fields, ['settings' => ['cap' => true], 'limits' => ['cap' => [1]]], true, new StubJsonApiRequest());

        self::assertSame(['/data/attributes/settings/cap', '/data/attributes/limits/cap'], \array_map(static fn(ValueTypeMismatch $m): string => $m->pointer, $found));
    }

    /**
     * @return list<ValueTypeMismatch>
     */
    private function mismatches(Union $field, mixed $value): array
    {
        return ValueTypes::mismatchesIn([$field], [$field->name() => $value], true, new StubJsonApiRequest());
    }

    private function value(): Union
    {
        return Union::make('value')->of(
            Str::make()->maxLength(20),
            Decimal::make(),
            Boolean::make(),
            ArrayList::make()->maxItems(5)->of(Union::of(Str::make(), Decimal::make(), Boolean::make())),
        )->nullable()->build();
    }
}
