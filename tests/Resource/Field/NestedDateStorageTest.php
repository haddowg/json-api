<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A date child of an `Obj`, a `OneOf` variant or a structured `ArrayList` element stores
 * a string: a `DateTime` its instant in UTC, a `Date` or `Time` its wall-clock value in
 * the field's format. A top-level date still stores the object, and a hook still owns
 * the value.
 */
#[CoversClass(DateTime::class)]
#[CoversClass(Date::class)]
#[CoversClass(Time::class)]
#[CoversClass(Obj::class)]
final class NestedDateStorageTest extends TestCase
{
    private string $defaultZone;

    protected function setUp(): void
    {
        $this->defaultZone = \date_default_timezone_get();
        // A non-UTC default, so an offset-less input is seen to be read in it.
        \date_default_timezone_set('America/New_York');
    }

    protected function tearDown(): void
    {
        \date_default_timezone_set($this->defaultZone);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function instants(): iterable
    {
        yield 'a positive offset' => ['2026-11-03T17:52:53+02:00', '2026-11-03T15:52:53.000000+00:00'];
        yield 'a negative offset' => ['2026-11-03T10:52:53-05:00', '2026-11-03T15:52:53.000000+00:00'];
        yield 'a half-hour offset' => ['2026-11-03T21:22:53+05:30', '2026-11-03T15:52:53.000000+00:00'];
        yield 'a UTC designator' => ['2026-11-03T15:52:53Z', '2026-11-03T15:52:53.000000+00:00'];
        yield 'microseconds' => ['2026-11-03T16:52:53.123456+01:00', '2026-11-03T15:52:53.123456+00:00'];
        yield 'no offset, read in the default zone' => ['2026-11-03 10:52:53', '2026-11-03T15:52:53.000000+00:00'];
    }

    #[Test]
    #[DataProvider('instants')]
    public function anObjDateTimeChildStoresItsInstantInUtc(string $input, string $stored): void
    {
        $field = Obj::make('window')->fields(DateTime::make('startsAt'))->build();

        self::assertSame(['window' => ['startsAt' => $stored]], $this->hydrate($field, ['startsAt' => $input]));
    }

    #[Test]
    public function anInputTimezoneKeepsTheInstantAnOffsetLessInputNames(): void
    {
        $nested = Obj::make('window')->fields(DateTime::make('startsAt')->inputTimezone('Europe/Paris'))->build();
        $topLevel = DateTime::make('startsAt')->inputTimezone('Europe/Paris')->build();

        $object = $this->hydrate($topLevel, '2026-11-03 10:52:53')['startsAt'];
        self::assertInstanceOf(\DateTimeImmutable::class, $object);
        self::assertSame('Europe/Paris', $object->getTimezone()->getName());

        self::assertSame(
            ['window' => ['startsAt' => $object->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP')]],
            $this->hydrate($nested, ['startsAt' => '2026-11-03 10:52:53']),
        );
    }

    #[Test]
    public function dateAndTimeChildrenStoreTheirWallClockValue(): void
    {
        $field = Obj::make('opening')->fields(
            Date::make('day')->inputTimezone('Pacific/Kiritimati'),
            Time::make('opensAt')->inputTimezone('Pacific/Kiritimati'),
            Time::make('closesAt'),
            Date::make('ukDay')->format('d/m/Y'),
        )->build();

        self::assertSame(
            ['opening' => ['day' => '2026-11-03', 'opensAt' => '09:15:00', 'closesAt' => '17:30:00', 'ukDay' => '03/11/2026']],
            $this->hydrate($field, ['day' => '2026-11-03', 'opensAt' => '09:15:00', 'closesAt' => '17:30:00+02:00', 'ukDay' => '2026-11-03']),
        );
    }

    #[Test]
    public function dateAndTimeChildrenRenderWhatTheyStore(): void
    {
        $field = Obj::make('opening')->fields(Date::make('day'), Time::make('opensAt'), Date::make('ukDay')->format('d/m/Y'))->build();

        self::assertSame(
            ['day' => '2026-11-03', 'opensAt' => '09:15:00', 'ukDay' => '03/11/2026'],
            $this->roundTrip($field, ['day' => '2026-11-03', 'opensAt' => '09:15:00', 'ukDay' => '2026-11-03']),
        );
    }

    #[Test]
    public function aOneOfVariantDateTimeStoresItsInstantInUtc(): void
    {
        $field = OneOf::make('slot')->variant('timed', DateTime::make('startsAt'), Date::make('day'))->build();

        self::assertSame(
            ['slot' => ['type' => 'timed', 'startsAt' => '2026-11-03T15:52:53.000000+00:00', 'day' => '2026-11-03']],
            $this->hydrate($field, ['type' => 'timed', 'startsAt' => '2026-11-03T17:52:53+02:00', 'day' => '2026-11-03']),
        );
    }

    #[Test]
    public function aStructuredListOfObjStoresEachElementsDateAsAString(): void
    {
        $field = ArrayList::make('sources')->of(Obj::make('source')->fields(DateTime::make('retrievedAt')))->build();

        self::assertSame(
            ['sources' => [['retrievedAt' => '2026-11-03T15:52:53.000000+00:00'], ['retrievedAt' => '2026-11-03T16:00:00.000000+00:00']]],
            $this->hydrate($field, [['retrievedAt' => '2026-11-03T17:52:53+02:00'], ['retrievedAt' => '2026-11-03T11:00:00-05:00']]),
        );
    }

    #[Test]
    public function aStructuredListOfOneOfStoresEachElementsDateAsAString(): void
    {
        $field = ArrayList::make('steps')->of(
            OneOf::make('step')
                ->variant('wait', DateTime::make('until'))
                ->variant('open', Time::make('at')),
        )->build();

        self::assertSame(
            ['steps' => [['type' => 'wait', 'until' => '2026-11-03T15:52:53.000000+00:00'], ['type' => 'open', 'at' => '08:00:00']]],
            $this->hydrate($field, [['type' => 'wait', 'until' => '2026-11-03T16:52:53+01:00'], ['type' => 'open', 'at' => '08:00:00']]),
        );
    }

    #[Test]
    public function aDeeplyNestedDateTimeStoresAString(): void
    {
        $field = Obj::make('plan')->fields(
            Obj::make('phase')->fields(
                ArrayList::make('slots')->of(
                    OneOf::make('slot')->variant('timed', Obj::make('window')->fields(DateTime::make('startsAt'))),
                ),
            ),
        )->build();

        $model = $this->hydrate($field, ['phase' => ['slots' => [['type' => 'timed', 'window' => ['startsAt' => '2026-11-03T17:52:53+02:00']]]]]);

        self::assertSame(
            ['plan' => ['phase' => ['slots' => [['type' => 'timed', 'window' => ['startsAt' => '2026-11-03T15:52:53.000000+00:00']]]]]],
            $model,
        );
    }

    #[Test]
    public function anObjInsideAMapStoresItsDateAsAStringWhileTheMapsOwnDateStaysAnObject(): void
    {
        $field = Map::make('release')->fields(
            DateTime::make('releasedAt'),
            Obj::make('window')->fields(DateTime::make('startsAt')),
        )->build();

        $model = $this->hydrate($field, ['releasedAt' => '2026-11-03T17:52:53+02:00', 'window' => ['startsAt' => '2026-11-03T17:52:53+02:00']]);

        self::assertInstanceOf(\DateTimeImmutable::class, $model['releasedAt'] ?? null);
        self::assertSame(['startsAt' => '2026-11-03T15:52:53.000000+00:00'], $model['window'] ?? null);
    }

    /**
     * @return iterable<string, array{FieldInterface}>
     */
    public static function topLevelDates(): iterable
    {
        yield 'DateTime' => [DateTime::make('at')->build()];
        yield 'Date' => [Date::make('at')->build()];
        yield 'Time' => [Time::make('at')->build()];
    }

    #[Test]
    #[DataProvider('topLevelDates')]
    public function aTopLevelDateStillStoresAnObject(FieldInterface $field): void
    {
        self::assertInstanceOf(\DateTimeImmutable::class, $this->hydrate($field, '2026-11-03T17:52:53+02:00')['at'] ?? null);
    }

    #[Test]
    public function aChildsOwnHooksStillOwnTheValue(): void
    {
        $field = Obj::make('window')->fields(
            DateTime::make('startsAt')->deserializeUsing(static fn(mixed $value): mixed => new \DateTimeImmutable(\is_string($value) ? $value : 'now')),
            DateTime::make('endsAt')->fillUsing(static function (mixed $nested, mixed $value): array {
                \assert(\is_array($nested) && \is_string($value));
                $nested['ends'] = ['raw' => $value];

                return $nested;
            }),
        )->build();

        $model = $this->hydrate($field, ['startsAt' => '2026-11-03T17:52:53+02:00', 'endsAt' => '2026-11-03T18:00:00+02:00']);
        $window = $model['window'] ?? null;

        self::assertIsArray($window);
        self::assertInstanceOf(\DateTimeImmutable::class, $window['startsAt'] ?? null);
        self::assertSame(['raw' => '2026-11-03T18:00:00+02:00'], $window['ends'] ?? null);
    }

    #[Test]
    public function aParentFillUsingStillOwnsTheValue(): void
    {
        $at = new \DateTimeImmutable('2026-11-03T17:52:53+02:00');
        $field = Obj::make('window')
            ->fields(DateTime::make('startsAt'))
            ->fillUsing(static function (mixed $model) use ($at): array {
                \assert(\is_array($model));
                $model['window'] = ['startsAt' => $at];

                return $model;
            })
            ->build();

        self::assertSame(['window' => ['startsAt' => $at]], $this->hydrate($field, ['startsAt' => '2026-11-03T17:52:53+02:00']));
    }

    #[Test]
    public function aNullOrInvalidNestedDateBehavesAsBefore(): void
    {
        $field = Obj::make('window')->fields(DateTime::make('startsAt')->nullable())->build();

        self::assertSame(['window' => ['startsAt' => null]], $this->hydrate($field, ['startsAt' => null]));

        $this->expectException(AttributeValueInvalid::class);
        $this->expectExceptionMessage('"window/startsAt"');
        $this->hydrate($field, ['startsAt' => '2026-02-30T10:00:00Z']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function legacyStoredValues(): iterable
    {
        yield 'a DateTimeImmutable' => [new \DateTimeImmutable('2026-11-03 17:52:53', new \DateTimeZone('Europe/Athens'))];
        yield 'a dump' => [['date' => '2026-11-03 17:52:53.000000', 'timezone_type' => 3, 'timezone' => 'Europe/Athens']];
    }

    #[Test]
    #[DataProvider('legacyStoredValues')]
    public function aPatchMergingOntoALegacyStoredValueRendersBoth(mixed $legacy): void
    {
        $field = Obj::make('window')->fields(DateTime::make('startsAt'), DateTime::make('endsAt'))->build();
        $request = StubJsonApiRequest::create();

        $model = $field->hydrate(['window' => ['startsAt' => $legacy]], ['endsAt' => '2026-11-03T20:00:00+02:00'], [], $request, false);
        self::assertIsArray($model);
        self::assertIsArray($model['window'] ?? null);
        self::assertSame($legacy, $model['window']['startsAt'] ?? null);
        self::assertSame('2026-11-03T18:00:00.000000+00:00', $model['window']['endsAt'] ?? null);

        self::assertSame(
            ['startsAt' => '2026-11-03T17:52:53+02:00', 'endsAt' => '2026-11-03T18:00:00+00:00'],
            $field->serialize($model, $request, 'window'),
        );
        self::assertSame(
            ['startsAt' => '2026-11-03T15:52:53+00:00', 'endsAt' => '2026-11-03T18:00:00+00:00'],
            $field->withDefaultOutputTimezone('UTC')->serialize($model, $request, 'window'),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function hydrate(FieldInterface $field, mixed $input): array
    {
        $model = $field->hydrate([], $input, [], StubJsonApiRequest::create(), true);
        self::assertIsArray($model);

        return $model;
    }

    private function roundTrip(FieldInterface $field, mixed $input): mixed
    {
        $stored = \json_decode(\json_encode($this->hydrate($field, $input), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        return $field->serialize($stored, StubJsonApiRequest::create(), $field->name());
    }
}
