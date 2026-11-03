<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A nested date child hydrates to a `\DateTimeInterface`, which a JSON column stores as
 * PHP's `{date, timezone_type, timezone}` dump. Reading it back must still render the
 * formatted date-time.
 */
#[CoversClass(DateTime::class)]
#[CoversClass(Date::class)]
#[CoversClass(Time::class)]
final class JsonStoredDateTest extends TestCase
{
    #[Test]
    public function anObjDateTimeChildSurvivesAJsonColumn(): void
    {
        $field = Obj::make('window')->fields(DateTime::make('startsAt'), Str::make('label'))->build();

        self::assertSame(
            ['startsAt' => '2024-07-01T12:30:00+02:00', 'label' => 'launch'],
            $this->roundTrip($field, ['startsAt' => '2024-07-01T12:30:00+02:00', 'label' => 'launch']),
        );
    }

    #[Test]
    public function aOneOfDateTimeChildSurvivesAJsonColumn(): void
    {
        $field = OneOf::make('slot')->variant('timed', DateTime::make('startsAt'))->build();

        self::assertSame(
            ['type' => 'timed', 'startsAt' => '2024-07-01T12:30:00+02:00'],
            $this->roundTrip($field, ['type' => 'timed', 'startsAt' => '2024-07-01T12:30:00+02:00']),
        );
    }

    #[Test]
    public function aStructuredListDateTimeChildSurvivesAJsonColumn(): void
    {
        $field = ArrayList::make('sources')->of(Obj::make('source')->fields(DateTime::make('retrievedAt')))->build();

        self::assertSame(
            [['retrievedAt' => '2024-07-01T12:30:00+00:00'], ['retrievedAt' => '2024-07-02T08:00:00-05:00']],
            $this->roundTrip($field, [['retrievedAt' => '2024-07-01T12:30:00Z'], ['retrievedAt' => '2024-07-02T08:00:00-05:00']]),
        );
    }

    #[Test]
    public function aJsonStoredDateTimeHonoursTheDefaultOutputTimezone(): void
    {
        $field = Obj::make('window')->fields(DateTime::make('startsAt'))->build()->withDefaultOutputTimezone('UTC');

        self::assertSame(['startsAt' => '2024-07-01T10:30:00+00:00'], $this->roundTrip($field, ['startsAt' => '2024-07-01T12:30:00+02:00']));
    }

    #[Test]
    public function aJsonStoredDateAndTimeRenderInTheirOwnFormats(): void
    {
        $field = Obj::make('opening')->fields(Date::make('day'), Time::make('opensAt'))->build();

        self::assertSame(
            ['day' => '2024-07-01', 'opensAt' => '09:15:00'],
            $this->roundTrip($field, ['day' => '2024-07-01', 'opensAt' => '09:15:00']),
        );
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function storedValues(): iterable
    {
        yield 'a named zone dump' => [
            ['date' => '2024-07-01 12:30:00.000000', 'timezone_type' => 3, 'timezone' => 'Europe/Paris'],
            '2024-07-01T12:30:00+02:00',
        ];
        yield 'an offset dump' => [
            ['date' => '2024-07-01 12:30:00.000000', 'timezone_type' => 1, 'timezone' => '-05:00'],
            '2024-07-01T12:30:00-05:00',
        ];
        yield 'an abbreviation dump' => [
            ['date' => '2024-07-01 12:30:00.000000', 'timezone_type' => 2, 'timezone' => 'BST'],
            '2024-07-01T12:30:00+01:00',
        ];
        yield 'a decoded-as-object dump' => [
            (object) ['date' => '2024-07-01 12:30:00.000000', 'timezone_type' => 3, 'timezone' => 'UTC'],
            '2024-07-01T12:30:00+00:00',
        ];
        yield 'an RFC 3339 string' => ['2024-07-01T12:30:00.5+02:00', '2024-07-01T12:30:00+02:00'];
        yield 'a UTC designator' => ['2024-07-01T12:30:00Z', '2024-07-01T12:30:00+00:00'];
        yield 'a space-separated ISO 8601 string' => ['2024-07-01 12:30:00+0200', '2024-07-01T12:30:00+02:00'];
    }

    #[Test]
    #[DataProvider('storedValues')]
    public function aDateTimeRendersAStoredValue(mixed $stored, string $expected): void
    {
        self::assertSame($expected, $this->render(DateTime::make('at')->build(), $stored));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function untouchedValues(): iterable
    {
        yield 'an offset-less string' => ['2024-07-01 12:30:00'];
        yield 'a date-only string' => ['2024-07-01'];
        yield 'a relative string' => ['tomorrow'];
        yield 'an impossible date' => ['2026-02-30T10:00:00Z'];
        yield 'an empty string' => [''];
        yield 'an integer' => [1719829800];
        yield 'an unrelated array' => [['date' => '2024-07-01']];
        yield 'a dump with an unknown zone' => [['date' => '2024-07-01 12:30:00.000000', 'timezone_type' => 3, 'timezone' => 'Mars/Olympus']];
        yield 'a dump with a malformed date' => [['date' => 'yesterday', 'timezone_type' => 3, 'timezone' => 'UTC']];
        yield 'a dump with extra members' => [['date' => '2024-07-01 12:30:00.000000', 'timezone_type' => 3, 'timezone' => 'UTC', 'note' => 'x']];
    }

    #[Test]
    #[DataProvider('untouchedValues')]
    public function aValueThatIsNotAnInstantPassesThroughUnchanged(mixed $stored): void
    {
        self::assertSame($stored, $this->render(DateTime::make('at')->build(), $stored));
    }

    #[Test]
    public function aCustomFormatWireValuePassesThroughUnchanged(): void
    {
        // `05/09/2024` would read as 9 May if parsed, so only ISO 8601 shapes are.
        self::assertSame('05/09/2024 09:08', $this->render(DateTime::make('at')->format('d/m/Y H:i')->build(), '05/09/2024 09:08'));
    }

    #[Test]
    public function aStoredStringMovesIntoTheOutputTimezone(): void
    {
        self::assertSame(
            '2024-07-01T10:30:00+00:00',
            $this->render(DateTime::make('at')->outputTimezone('UTC')->build(), '2024-07-01T12:30:00+02:00'),
        );
    }

    #[Test]
    public function aStoredDumpRendersInADateFormat(): void
    {
        self::assertSame(
            '2024-07-01',
            $this->render(Date::make('day')->build(), ['date' => '2024-07-01 00:00:00.000000', 'timezone_type' => 3, 'timezone' => 'Europe/Paris']),
        );
    }

    /**
     * Hydrates `$input`, stores the field's value the way a JSON column does, then
     * serializes what comes back.
     */
    private function roundTrip(FieldInterface $field, mixed $input): mixed
    {
        $request = StubJsonApiRequest::create();
        $model = $field->hydrate([], $input, [], $request, true);
        self::assertIsArray($model);

        $stored = \json_decode(\json_encode($model, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        return $field->serialize($stored, $request, $field->name());
    }

    private function render(DateTime $field, mixed $value): mixed
    {
        return $field->serialize([$field->name() => $value], StubJsonApiRequest::create(), $field->name());
    }
}
