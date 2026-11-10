<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Resource\Constraint\MaxProperties;
use haddowg\JsonApi\Resource\Constraint\MinProperties;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\FieldState;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\ObjBuilder;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An `Obj` declaring `additionalProperties()` takes members it does not declare, each
 * read and written through that one field, beside its declared children.
 */
#[CoversClass(Obj::class)]
#[CoversClass(ObjBuilder::class)]
final class OpenObjTest extends TestCase
{
    private const string AT = '2024-07-01T12:30:00+00:00';

    // ---- Declaring ------------------------------------------------------------------

    #[Test]
    public function theMemberFieldAndCountsAreCarriedOnTheBuiltObject(): void
    {
        $field = Obj::make('row')->additionalProperties(Str::make('*')->maxLength(200))->minProperties(1)->maxProperties(50)->build();

        self::assertInstanceOf(Str::class, $field->additionalProperties());
        self::assertEquals([new MinProperties(1), new MaxProperties(50)], $field->constraints());
        self::assertNull(Obj::make('closed')->build()->additionalProperties());
    }

    #[Test]
    public function aMemberFieldThatCannotHoldAMemberIsRefused(): void
    {
        foreach ([
            'relation' => BelongsTo::make('author', 'people'),
            'hook' => Str::make('*')->fillUsing(static fn(mixed $model): mixed => $model),
            'computed' => Str::make('*')->computedUsing(static fn(mixed $model): string => 'x'),
            'flattened' => Str::make('*')->on('author'),
        ] as $case => $value) {
            try {
                Obj::make('row')->additionalProperties($value)->build();
                self::fail("Expected the {$case} member field to be refused.");
            } catch (\LogicException $e) {
                self::assertStringContainsString('additionalProperties()', $e->getMessage(), $case);
            }
        }
    }

    #[Test]
    public function aOneOfVariantCannotBeOpen(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('variant "open"');

        new OneOf(new FieldState('shape', 'shape'), 'kind', ['open' => Obj::make('open')->additionalProperties(Str::make('*'))->build()]);
    }

    // ---- Reads ----------------------------------------------------------------------

    #[Test]
    public function undeclaredStoredMembersRenderThroughTheMemberFieldAfterTheChildren(): void
    {
        $field = Obj::make('row')
            ->fields(Str::make('key')->storedAs('k'), Str::make('secret')->hidden())
            ->additionalProperties(DateTime::make('*'))
            ->build();

        $stored = ['Seen' => new \DateTimeImmutable(self::AT), 'k' => 'RFP-1', 'secret' => 's', 'key' => 'shadowed'];

        // A key a child declares, by name or by its stored key, is never an extra member.
        self::assertSame(['key' => 'RFP-1', 'Seen' => self::AT], $field->serialize(['row' => $stored], $this->request(), 'row'));
    }

    #[Test]
    public function aMemberFieldHiddenOrWriteOnlyKeepsTheMembersOff(): void
    {
        foreach ([Str::make('*')->hidden(), Str::make('*')->writeOnly()] as $value) {
            $field = Obj::make('row')->fields(Str::make('key'))->additionalProperties($value)->build();

            self::assertSame(['key' => 'a'], $field->serialize(['row' => ['key' => 'a', 'Vendor' => 'Acme']], $this->request(), 'row'));
        }
    }

    #[Test]
    public function aComputedValueRendersItsUndeclaredKeysThroughTheMemberField(): void
    {
        $field = Obj::make('row')
            ->fields(Str::make('key'))
            ->additionalProperties(DateTime::make('*'))
            ->extractUsing(static fn(mixed $model): array => ['Seen' => new \DateTimeImmutable(self::AT), 'key' => 'a'])
            ->build();

        self::assertSame(['key' => 'a', 'Seen' => self::AT], $field->serialize([], $this->request(), 'row'));
    }

    #[Test]
    public function anEmptyOpenObjectStaysAnObject(): void
    {
        $field = Obj::make('labels')->additionalProperties(Str::make('*'))->build();

        self::assertEquals(new \stdClass(), $field->serialize(['labels' => []], $this->request(), 'labels'));
    }

    // ---- Writes ---------------------------------------------------------------------

    #[Test]
    public function undeclaredMembersHydrateBesideTheChildren(): void
    {
        $field = Obj::make('row')->fields(Str::make('key')->storedAs('k'))->additionalProperties(Str::make('*'))->build();

        self::assertSame(
            ['row' => ['k' => 'RFP-123', 'Vendor' => 'Acme Media', 'CPL' => '42']],
            $field->hydrate([], ['key' => 'RFP-123', 'Vendor' => 'Acme Media', 'CPL' => '42'], [], $this->request(), true),
        );
    }

    #[Test]
    public function aPartialUpdateMergesMembersOntoTheStoredObject(): void
    {
        $field = Obj::make('labels')->additionalProperties(Str::make('*'))->build();

        self::assertSame(
            ['labels' => ['a' => '1', 'b' => '3', 'c' => '4']],
            $field->hydrate(['labels' => ['a' => '1', 'b' => '2']], ['b' => '3', 'c' => '4'], [], $this->request(), false),
        );
    }

    #[Test]
    public function aStoredKeyOfAChildCannotBeWrittenAsAMember(): void
    {
        $field = Obj::make('row')->fields(Str::make('key')->storedAs('k'))->additionalProperties(Str::make('*'))->build();

        self::assertSame(['row' => ['k' => 'kept']], $field->hydrate(['row' => ['k' => 'kept']], ['k' => 'smuggled'], [], $this->request(), false));
    }

    #[Test]
    public function memberValuesGoThroughTheMemberFieldsCast(): void
    {
        $field = Obj::make('seen')->additionalProperties(DateTime::make('*'))->build();

        // A date member stores its string, as a nested date child does.
        self::assertSame(['seen' => ['a' => '2024-07-01T12:30:00.000000+00:00']], $field->hydrate([], ['a' => self::AT], [], $this->request(), true));
    }

    #[Test]
    public function aReadOnlyMemberFieldIgnoresTheMembers(): void
    {
        $field = Obj::make('metrics')->fields(Str::make('label'))->additionalProperties(Integer::make('*')->readOnly())->build();

        self::assertSame(
            ['metrics' => ['views' => 3, 'label' => 'x']],
            $field->hydrate(['metrics' => ['views' => 3]], ['label' => 'x', 'views' => 9, 'clicks' => 1], [], $this->request(), false),
        );
    }

    #[Test]
    public function anInvalidMemberIsReportedAtItsEscapedKey(): void
    {
        $field = Obj::make('seen')->additionalProperties(DateTime::make('*'))->build();

        try {
            $field->hydrate([], ['Due ~ a/b' => 'banana'], [], $this->request(), true);
            self::fail('Expected AttributeValueInvalid');
        } catch (AttributeValueInvalid $e) {
            self::assertSame('seen/Due ~0 a~1b', $e->attribute);
        }
    }

    #[Test]
    public function aListElementIsBuiltWithItsMembers(): void
    {
        $field = ArrayList::make('rows')
            ->of(Obj::make('row')->additionalProperties(Str::make('*')->maxLength(200))->maxProperties(50))
            ->maxItems(1000)
            ->build();
        $rows = [['Key' => 'RFP-123', 'Vendor' => 'Acme Media', 'CPL' => '42'], ['Key' => 'RFP-124']];

        self::assertSame(['rows' => $rows], $field->hydrate([], $rows, [], $this->request(), true));
        self::assertSame($rows, $field->serialize(['rows' => $rows], $this->request(), 'rows'));
    }

    #[Test]
    public function anInvalidMemberOfAListElementPointsThroughTheIndex(): void
    {
        $field = ArrayList::make('rows')->of(Obj::make('row')->additionalProperties(DateTime::make('*')))->build();

        try {
            $field->hydrate([], [['a' => self::AT], ['b/c' => 'banana']], [], $this->request(), true);
            self::fail('Expected AttributeValueInvalid');
        } catch (AttributeValueInvalid $e) {
            self::assertSame('rows/1/b~1c', $e->attribute);
        }
    }

    #[Test]
    public function anOpenObjectNestedInAnotherHydratesItsMembers(): void
    {
        $field = Obj::make('settings')->fields(
            Obj::make('labels')->additionalProperties(Str::make('*')),
        )->additionalProperties(Obj::make('*')->additionalProperties(Integer::make('*')))->build();

        self::assertSame(
            ['settings' => ['labels' => ['x' => 'y'], 'counts' => ['a' => 1]]],
            $field->hydrate([], ['labels' => ['x' => 'y'], 'counts' => ['a' => 1]], [], $this->request(), true),
        );
    }

    #[Test]
    public function theDefaultOutputZoneReachesTheMemberField(): void
    {
        $field = Obj::make('seen')->additionalProperties(DateTime::make('*'))->build()->withDefaultOutputTimezone('Europe/London');

        self::assertSame(['a' => '2024-07-01T13:30:00+01:00'], $field->serialize(['seen' => ['a' => new \DateTimeImmutable(self::AT)]], $this->request(), 'seen'));
    }

    private function request(): StubJsonApiRequest
    {
        return new StubJsonApiRequest();
    }
}
