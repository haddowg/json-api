<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\ArrayListBuilder;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Email;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Resource\Field\UnionBuilder;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A `Union` takes one JSON primitive type per member, or a list of them: a written value's
 * JSON type picks the member that casts it, and a stored value's PHP type the member that
 * renders it.
 */
#[CoversClass(Union::class)]
#[CoversClass(UnionBuilder::class)]
#[CoversClass(ArrayList::class)]
#[CoversClass(ArrayListBuilder::class)]
final class UnionTest extends TestCase
{
    private const string AT = '2024-07-01T12:30:00+00:00';

    // ---- Declaring ------------------------------------------------------------------

    #[Test]
    public function membersAreOrderedByJsonTypeWhateverTheDeclarationOrder(): void
    {
        $field = Union::make('value')->of(ArrayList::make()->of('string'), Boolean::make(), Integer::make(), Str::make())->build();

        self::assertSame(['string', 'integer', 'boolean', 'array'], $field->types());
        self::assertSame('string|integer|boolean|array', $field->valueType());
        self::assertCount(4, $field->members());
    }

    #[Test]
    public function anUnnamedUnionIsAnElementUnion(): void
    {
        $list = ArrayList::make('values')->of(Union::of(Str::make(), Decimal::make()))->build();

        self::assertNull($list->element());
        self::assertInstanceOf(Union::class, $list->elementUnion());
        self::assertSame('', $list->elementUnion()->name());
        self::assertSame('string|number', $list->elementType());
    }

    #[Test]
    public function eachStringFamilyFieldIsAStringMember(): void
    {
        foreach ([Str::make(), Email::make(), DateTime::make(), Date::make(), Str::make()->uuid()] as $member) {
            self::assertSame(['string'], Union::make('value')->of($member)->build()->types());
        }
    }

    #[Test]
    public function twoMembersOfOneJsonTypeAreRefused(): void
    {
        foreach ([
            'two strings' => [Str::make(), Date::make()],
            'integer and decimal' => [Integer::make(), Decimal::make()],
            'two booleans' => [Boolean::make(), Boolean::make()],
            'two lists' => [ArrayList::make()->of('string'), ArrayList::make()->of('integer')],
        ] as $case => $members) {
            try {
                Union::make('value')->of(...$members)->build();
                self::fail("Expected {$case} to be refused.");
            } catch (\LogicException $e) {
                self::assertStringContainsString('one member per JSON type', $e->getMessage(), $case);
            }
        }
    }

    #[Test]
    public function anObjectOrUnionMemberIsRefused(): void
    {
        foreach ([
            'Obj' => Obj::make('o'),
            'OneOf' => OneOf::make('o')->discriminator('k')->variant('a'),
            'Map' => Map::make('m'),
            'ArrayHash' => ArrayHash::make('h'),
            'structured list' => ArrayList::make()->of(Obj::make('o')),
            'nested union' => Union::of(Str::make()),
            'relation' => BelongsTo::make('author', 'people'),
            'id' => Id::make(),
        ] as $case => $member) {
            try {
                Union::make('value')->of($member)->build();
                self::fail("Expected the {$case} member to be refused.");
            } catch (\LogicException $e) {
                self::assertStringContainsString('cannot take a', $e->getMessage(), $case);
            }
        }
    }

    #[Test]
    public function aMemberCarryingTheUnionsOwnRulesIsRefused(): void
    {
        foreach ([
            'nullable' => [Str::make()->nullable(), 'presence belongs to the union'],
            'required' => [Str::make()->required(), 'presence belongs to the union'],
            'hook' => [Str::make()->serializeUsing(static fn(): string => 'x'), 'put the hook on the union'],
            'read-only' => [Str::make()->readOnly(), 'visibility belongs to the union'],
            'hidden' => [Str::make()->hidden(), 'visibility belongs to the union'],
            'flattened' => [Str::make()->on('author'), 'needs no source of its own'],
        ] as $case => [$member, $message]) {
            try {
                Union::make('value')->of($member)->build();
                self::fail("Expected the {$case} member to be refused.");
            } catch (\LogicException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $case);
            }
        }
    }

    #[Test]
    public function aUnionNeedsAMember(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('needs at least one member');

        Union::make('value')->build();
    }

    #[Test]
    public function aListOfUnionsRefusesWhatAListOfMixedTypesCannotHonour(): void
    {
        foreach ([
            'sorted' => [ArrayList::make('values')->of(Union::of(Str::make()))->sorted(), 'sorted()'],
            'each' => [ArrayList::make('values')->of(Union::of(Str::make()))->each(new MinLength(1)), 'each()'],
            'nullable element' => [ArrayList::make('values')->of(Union::of(Str::make())->nullable()), 'never holds null'],
            'hooked element' => [ArrayList::make('values')->of(Union::of(Str::make())->fillUsing(static fn(mixed $model): mixed => $model)), 'hook'],
        ] as $case => [$builder, $message]) {
            try {
                $builder->build();
                self::fail("Expected the {$case} list to be refused.");
            } catch (\LogicException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $case);
            }
        }
    }

    #[Test]
    public function aLaterElementReplacesTheUnion(): void
    {
        $list = ArrayList::make('values')->of(Union::of(Str::make()))->of('integer')->build();

        self::assertNull($list->elementUnion());
        self::assertSame('integer', $list->elementType());
    }

    #[Test]
    public function aResourceRefusesAnUnnamedField(): void
    {
        $resource = new class extends AbstractResource {
            public static string $type = 'things';

            public function fields(): array
            {
                return [Id::make(), Str::make()];
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no name');

        $resource->allFields();
    }

    // ---- Reads ----------------------------------------------------------------------

    #[Test]
    public function aStoredValueRendersThroughTheMemberItsPhpTypeSelects(): void
    {
        $field = $this->value()->build();

        foreach ([
            'a string' => ['abc', 'abc'],
            'an int, through the number member' => [3, 3.0],
            'a float' => [2.5, 2.5],
            'a bool' => [false, false],
            'a list, each element through its own member' => [['a', 1, true], ['a', 1.0, true]],
            'null' => [null, null],
        ] as $case => [$stored, $rendered]) {
            self::assertSame($rendered, $field->serialize(['value' => $stored], $this->request(), 'value'), $case);
        }

        // A backed enum goes by its value, so a string-backed one reaches the string member,
        // which leaves it for the encoder as a Str does.
        self::assertSame('"draft"', \json_encode($field->serialize(['value' => UnionTestKind::Draft], $this->request(), 'value')));
        self::assertSame(UnionTestKind::Draft, Union::make('value')->of(Str::make())->build()->serialize(['value' => UnionTestKind::Draft], $this->request(), 'value'));
    }

    #[Test]
    public function aDateMemberRendersFromTheObjectItHydratesTo(): void
    {
        $field = Union::make('when')->of(DateTime::make()->outputTimezone('UTC'), Boolean::make())->build();

        self::assertSame(self::AT, $field->serialize(['when' => new \DateTimeImmutable(self::AT)], $this->request(), 'when'));
        self::assertSame(self::AT, $field->serialize(['when' => '2024-07-01T14:30:00+02:00'], $this->request(), 'when'));
        self::assertTrue($field->serialize(['when' => true], $this->request(), 'when'));
    }

    #[Test]
    public function aStoredValueNoMemberTakesRendersUnchanged(): void
    {
        $field = Union::make('value')->of(Str::make(), Boolean::make())->build();

        self::assertSame(42, $field->serialize(['value' => 42], $this->request(), 'value'));
        self::assertSame(['k' => 'v'], $field->serialize(['value' => ['k' => 'v']], $this->request(), 'value'));
        // A \DateTimeInterface needs a date member to render it.
        $date = new \DateTimeImmutable(self::AT);
        self::assertSame($date, $field->serialize(['value' => $date], $this->request(), 'value'));
    }

    #[Test]
    public function aComputedValueRendersThroughItsMemberToo(): void
    {
        $field = Union::make('value')->of(Decimal::make(), Boolean::make())->computedUsing(static fn(): int => 4)->build();

        self::assertSame(4.0, $field->serialize([], $this->request(), 'value'));
    }

    #[Test]
    public function theServersOutputZoneReachesADateMemberAndAListsElements(): void
    {
        $field = Union::make('value')
            ->of(DateTime::make(), ArrayList::make()->of(Union::of(DateTime::make(), Boolean::make())))
            ->build()
            ->withDefaultOutputTimezone('Europe/Paris');

        $at = new \DateTimeImmutable(self::AT);
        self::assertSame('2024-07-01T14:30:00+02:00', $field->serialize(['value' => $at], $this->request(), 'value'));
        self::assertSame(['2024-07-01T14:30:00+02:00', true], $field->serialize(['value' => [$at, true]], $this->request(), 'value'));
    }

    // ---- Writes ---------------------------------------------------------------------

    #[Test]
    public function aWrittenValueIsCastByTheMemberItsJsonTypeSelects(): void
    {
        $field = $this->value()->build();

        foreach ([
            'a string' => ['abc', 'abc'],
            'an integer, to the number member' => [3, 3.0],
            'a boolean' => [true, true],
            'a list' => [['a', 2, false], ['a', 2.0, false]],
            'null' => [null, null],
        ] as $case => [$written, $stored]) {
            self::assertSame(['value' => $stored], $field->hydrate([], $written, [], $this->request(), true), $case);
        }
    }

    #[Test]
    public function aValueNoMemberTakesIsStoredAsSentForTheValidator(): void
    {
        $field = Union::make('value')->of(Str::make(), Integer::make())->build();

        self::assertSame(['value' => 1.5], $field->hydrate([], 1.5, [], $this->request(), true));
        self::assertSame(['value' => ['k' => 'v']], $field->hydrate([], ['k' => 'v'], [], $this->request(), true));
    }

    #[Test]
    public function aTopLevelDateMemberHydratesToAnInstant(): void
    {
        $field = Union::make('when')->of(Date::make(), Integer::make())->build();

        $model = $field->hydrate([], '2024-07-01', [], $this->request(), true);

        self::assertIsArray($model);
        self::assertInstanceOf(\DateTimeImmutable::class, $model['when']);
        self::assertSame(['when' => 7], $field->hydrate([], 7, [], $this->request(), true));
    }

    #[Test]
    public function aNestedDateMemberStoresAString(): void
    {
        $field = Obj::make('settings')
            ->fields(Union::make('when')->of(DateTime::make(), Boolean::make()))
            ->build();

        self::assertSame(
            ['settings' => ['when' => '2024-07-01T10:30:00.000000+00:00']],
            $field->hydrate([], ['when' => '2024-07-01T12:30:00+02:00'], [], $this->request(), true),
        );
    }

    #[Test]
    public function aListElementDateMemberStoresAString(): void
    {
        $field = ArrayList::make('values')->of(Union::of(DateTime::make(), Boolean::make()))->build();

        self::assertSame(
            ['values' => ['2024-07-01T10:30:00.000000+00:00', false]],
            $field->hydrate([], ['2024-07-01T12:30:00+02:00', false], [], $this->request(), true),
        );
    }

    #[Test]
    public function anOpenObjectsMembersCanBeUnions(): void
    {
        $field = Obj::make('settings')->additionalProperties(Union::make('*')->of(Str::make(), Decimal::make()))->build();

        self::assertSame(['settings' => ['cap' => 2.0, 'tier' => 'gold']], $field->hydrate([], ['cap' => 2, 'tier' => 'gold'], [], $this->request(), true));
        self::assertSame(['cap' => 2.0, 'tier' => 'gold'], $field->serialize(['settings' => ['cap' => 2, 'tier' => 'gold']], $this->request(), 'settings'));
    }

    #[Test]
    public function aMemberCastFailurePointsAtTheValue(): void
    {
        foreach ([
            'top level' => [Union::make('when')->of(Date::make(), Integer::make())->build(), '2024-13-45', 'when'],
            'inside a list member' => [Union::make('when')->of(Integer::make(), ArrayList::make()->of(Union::of(Date::make(), Boolean::make())))->build(), [true, 'nope'], 'when/1'],
            'as a list element' => [ArrayList::make('when')->of(Union::of(Date::make(), Boolean::make()))->build(), [true, true, 'nope'], 'when/2'],
        ] as $case => [$field, $value, $attribute]) {
            try {
                $field->hydrate([], $value, [], $this->request(), true);
                self::fail("Expected {$case} to fail its cast.");
            } catch (AttributeValueInvalid $e) {
                self::assertSame($attribute, $e->attribute, $case);
            }
        }
    }

    #[Test]
    public function aNestedFailurePointsBelowTheObject(): void
    {
        $field = Obj::make('settings')->fields(Union::make('when')->of(Date::make(), Integer::make()))->build();

        try {
            $field->hydrate([], ['when' => 'nope'], [], $this->request(), true);
            self::fail('Expected the cast to fail.');
        } catch (AttributeValueInvalid $e) {
            self::assertSame('settings/when', $e->attribute);
        }
    }

    #[Test]
    public function aWriteHookOwnsTheValue(): void
    {
        $field = Union::make('value')->of(Str::make(), Decimal::make())
            ->fillUsing(static fn(mixed $model, mixed $value): array => ['raw' => $value])
            ->build();

        self::assertSame(['raw' => 3], $field->hydrate([], 3, [], $this->request(), true));
    }

    #[Test]
    public function castWireValueCastsThroughTheMember(): void
    {
        $field = $this->value()->build();

        self::assertSame(3.0, $field->castWireValue(3));
        self::assertSame(['a', 1.0], $field->castWireValue(['a', 1]));
    }

    private function value(): UnionBuilder
    {
        return Union::make('value')->of(
            Str::make()->maxLength(20),
            Decimal::make(),
            Boolean::make(),
            ArrayList::make()->maxItems(5)->of(Union::of(Str::make(), Decimal::make(), Boolean::make())),
        )->nullable();
    }

    private function request(): StubJsonApiRequest
    {
        return new StubJsonApiRequest();
    }
}

enum UnionTestKind: string
{
    case Draft = 'draft';
}
