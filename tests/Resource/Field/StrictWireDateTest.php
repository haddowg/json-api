<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Resource\Field\AbstractFieldBuilder;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A temporal attribute accepts a request string only in the shape its schema documents,
 * through the cast and before a `fillUsing()` or `deserializeUsing()` hook alike.
 */
#[CoversClass(DateTime::class)]
#[CoversClass(Date::class)]
#[CoversClass(Time::class)]
final class StrictWireDateTest extends TestCase
{
    private string $defaultZone;

    protected function setUp(): void
    {
        $this->defaultZone = \date_default_timezone_get();
        \date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        \date_default_timezone_set($this->defaultZone);
    }

    /**
     * Each field builder, the schema format it documents, a value it accepts with the
     * instant that value names, and the values it refuses.
     *
     * @return iterable<string, array{\Closure(): AbstractFieldBuilder, ?string, string, non-empty-string}>
     */
    public static function accepted(): iterable
    {
        $dateTime = static fn(): AbstractFieldBuilder => DateTime::make('at');
        yield 'date-time, UTC offset' => [$dateTime, 'date-time', '2026-11-05T09:42:00+00:00', '2026-11-05T09:42:00.000000+00:00'];
        yield 'date-time, Z' => [$dateTime, 'date-time', '2026-11-05T09:42:00Z', '2026-11-05T09:42:00.000000+00:00'];
        yield 'date-time, lower case t and z' => [$dateTime, 'date-time', '2026-11-05t09:42:00z', '2026-11-05T09:42:00.000000+00:00'];
        yield 'date-time, fraction' => [$dateTime, 'date-time', '2026-11-05T09:42:00.123Z', '2026-11-05T09:42:00.123000+00:00'];
        yield 'date-time, negative offset' => [$dateTime, 'date-time', '2026-11-05T09:42:00-05:00', '2026-11-05T09:42:00.000000-05:00'];
        yield 'date-time, leap day' => [$dateTime, 'date-time', '2024-02-29T00:00:00Z', '2024-02-29T00:00:00.000000+00:00'];

        $date = static fn(): AbstractFieldBuilder => Date::make('at');
        yield 'date' => [$date, 'date', '2026-11-05', '2026-11-05T00:00:00.000000+00:00'];
        yield 'date, leap day' => [$date, 'date', '2024-02-29', '2024-02-29T00:00:00.000000+00:00'];

        $time = static fn(): AbstractFieldBuilder => Time::make('at');
        yield 'time in its H:i:s format' => [$time, null, '09:42:00', 'T09:42:00.000000+00:00'];

        $zonedTime = static fn(): AbstractFieldBuilder => Time::make('at')->format('H:i:sP');
        yield 'time, Z' => [$zonedTime, 'time', '09:42:00Z', 'T09:42:00.000000+00:00'];
        yield 'time, offset' => [$zonedTime, 'time', '09:42:00+01:00', 'T09:42:00.000000+01:00'];
        yield 'time, fraction' => [$zonedTime, 'time', '09:42:00.5Z', 'T09:42:00.500000+00:00'];

        $dateOnly = static fn(): AbstractFieldBuilder => DateTime::make('at')->format('Y-m-d');
        yield 'a DateTime written as a date' => [$dateOnly, 'date', '2026-11-05', '2026-11-05T00:00:00.000000+00:00'];

        $custom = static fn(): AbstractFieldBuilder => DateTime::make('at')->format('d/m/Y H:i');
        yield 'a custom format' => [$custom, null, '05/11/2026 09:42', '2026-11-05T09:42:00.000000+00:00'];
    }

    /**
     * @return iterable<string, array{\Closure(): AbstractFieldBuilder, string}>
     */
    public static function refused(): iterable
    {
        $dateTime = static fn(): AbstractFieldBuilder => DateTime::make('at');
        yield 'date-time, relative' => [$dateTime, 'last year'];
        yield 'date-time, now' => [$dateTime, 'now'];
        yield 'date-time, no offset' => [$dateTime, '2026-11-05T09:42:00'];
        yield 'date-time, space and no offset' => [$dateTime, '2026-11-05 09:42'];
        yield 'date-time, space separator' => [$dateTime, '2026-11-05 09:42:00Z'];
        yield 'date-time, no seconds' => [$dateTime, '2026-11-05T09:42Z'];
        yield 'date-time, compact offset' => [$dateTime, '2026-11-05T09:42:00+0100'];
        yield 'date-time, a bare date' => [$dateTime, '2026-11-05'];
        yield 'date-time, a timestamp' => [$dateTime, '@1762335720'];
        yield 'date-time, an impossible day' => [$dateTime, '2026-02-30T00:00:00Z'];
        yield 'date-time, hour 24' => [$dateTime, '2026-02-28T24:00:00Z'];
        yield 'date-time, garbage' => [$dateTime, 'banana'];

        $date = static fn(): AbstractFieldBuilder => Date::make('at');
        yield 'date, relative' => [$date, 'tomorrow'];
        yield 'date, a date-time' => [$date, '2026-11-05T00:00:00Z'];
        yield 'date, unpadded' => [$date, '2026-11-5'];
        yield 'date, another order' => [$date, '05/11/2026'];
        yield 'date, an impossible day' => [$date, '2026-02-30'];
        yield 'date, not a leap year' => [$date, '2026-02-29'];

        $time = static fn(): AbstractFieldBuilder => Time::make('at');
        yield 'time, no seconds' => [$time, '09:42'];
        yield 'time, an offset its format does not write' => [$time, '09:42:00Z'];
        yield 'time, unpadded' => [$time, '9:42:00'];
        yield 'time, words' => [$time, 'noon'];
        yield 'time, hour 25' => [$time, '25:00:00'];

        $zonedTime = static fn(): AbstractFieldBuilder => Time::make('at')->format('H:i:sP');
        yield 'time, no offset' => [$zonedTime, '09:42:00'];
        yield 'time, relative' => [$zonedTime, '9am'];

        $custom = static fn(): AbstractFieldBuilder => DateTime::make('at')->format('d/m/Y H:i');
        yield 'a custom format, given RFC 3339' => [$custom, '2026-11-05T09:42:00Z'];
        yield 'a custom format, an impossible day' => [$custom, '30/02/2026 09:42'];
    }

    /**
     * @param \Closure(): AbstractFieldBuilder $builder
     */
    #[Test]
    #[DataProvider('accepted')]
    public function documentsTheFormatItAccepts(\Closure $builder, ?string $format, string $input, string $instant): void
    {
        $field = $builder()->build();
        self::assertInstanceOf(DateTime::class, $field);

        self::assertSame($format, $field->schemaFormat());
    }

    /**
     * @param \Closure(): AbstractFieldBuilder $builder
     * @param non-empty-string                 $instant
     */
    #[Test]
    #[DataProvider('accepted')]
    public function theCastAcceptsTheDocumentedShape(\Closure $builder, ?string $format, string $input, string $instant): void
    {
        $stored = $this->hydrate($builder()->build(), $input);

        self::assertInstanceOf(\DateTimeImmutable::class, $stored);
        self::assertStringEndsWith($instant, $stored->format('Y-m-d\TH:i:s.uP'));
    }

    /**
     * @param \Closure(): AbstractFieldBuilder $builder
     */
    #[Test]
    #[DataProvider('accepted')]
    public function aFillUsingHookReceivesTheAcceptedStringAsSent(\Closure $builder, ?string $format, string $input, string $instant): void
    {
        $field = $builder()->fillUsing(static fn(mixed $model, mixed $value): array => ['raw' => $value])->build();

        self::assertSame(['raw' => $input], $field->hydrate([], $input, [], StubJsonApiRequest::create(), true));
    }

    /**
     * @param \Closure(): AbstractFieldBuilder $builder
     */
    #[Test]
    #[DataProvider('accepted')]
    public function aDeserializeUsingHookReceivesTheAcceptedStringAsSent(\Closure $builder, ?string $format, string $input, string $instant): void
    {
        $field = $builder()->deserializeUsing(static fn(mixed $value): mixed => ['raw' => $value])->build();

        self::assertSame(['raw' => $input], $this->hydrate($field, $input));
    }

    /**
     * @param \Closure(): AbstractFieldBuilder $builder
     * @param non-empty-string                 $instant
     */
    #[Test]
    #[DataProvider('accepted')]
    public function parseWireAcceptsWhatTheCastDoes(\Closure $builder, ?string $format, string $input, string $instant): void
    {
        $field = $builder()->build();
        self::assertInstanceOf(DateTime::class, $field);

        self::assertNotNull($field->tryParseWire($input));
        self::assertStringEndsWith($instant, $field->parseWire($input)->format('Y-m-d\TH:i:s.uP'));
    }

    /**
     * @param \Closure(): AbstractFieldBuilder $builder
     */
    #[Test]
    #[DataProvider('refused')]
    public function theCastRefusesAnyOtherShape(\Closure $builder, string $input): void
    {
        $this->assertRefused($builder()->build(), $input, '/data/attributes/at');
    }

    /**
     * @param \Closure(): AbstractFieldBuilder $builder
     */
    #[Test]
    #[DataProvider('refused')]
    public function aFillUsingHookNeverSeesARefusedString(\Closure $builder, string $input): void
    {
        $field = $builder()->fillUsing(static fn(): never => self::fail('The hook ran.'))->build();

        $this->assertRefused($field, $input, '/data/attributes/at');
    }

    /**
     * @param \Closure(): AbstractFieldBuilder $builder
     */
    #[Test]
    #[DataProvider('refused')]
    public function aDeserializeUsingHookNeverSeesARefusedString(\Closure $builder, string $input): void
    {
        $field = $builder()->deserializeUsing(static fn(): never => self::fail('The hook ran.'))->build();

        $this->assertRefused($field, $input, '/data/attributes/at');
    }

    /**
     * @param \Closure(): AbstractFieldBuilder $builder
     */
    #[Test]
    #[DataProvider('refused')]
    public function parseWireRefusesWhatTheCastDoes(\Closure $builder, string $input): void
    {
        $field = $builder()->build();
        self::assertInstanceOf(DateTime::class, $field);

        self::assertNull($field->tryParseWire($input));
        $this->expectException(\InvalidArgumentException::class);
        $field->parseWire($input);
    }

    /**
     * @param \Closure(): AbstractFieldBuilder $builder
     */
    #[Test]
    #[DataProvider('refused')]
    public function castWireValueRefusesItToo(\Closure $builder, string $input): void
    {
        $this->expectException(AttributeValueInvalid::class);
        $builder()->build()->castWireValue($input);
    }

    #[Test]
    public function anEmptyOrNonStringValueIsLeftToValidation(): void
    {
        $field = DateTime::make('at')->fillUsing(static fn(mixed $model, mixed $value): array => ['raw' => $value])->build();

        self::assertSame(['raw' => ''], $field->hydrate([], '', [], StubJsonApiRequest::create(), true));
        self::assertSame(['raw' => null], $field->hydrate([], null, [], StubJsonApiRequest::create(), true));
        self::assertSame(['raw' => 42], $field->hydrate([], 42, [], StubJsonApiRequest::create(), true));
    }

    #[Test]
    public function aCustomFormatIsReadAsItIsWrittenNotAsTheConstructorWouldReadIt(): void
    {
        $field = Date::make('at')->format('d/m/Y')->build();

        $stored = $this->hydrate($field, '03/11/2026');

        self::assertInstanceOf(\DateTimeImmutable::class, $stored);
        self::assertSame('2026-11-03', $stored->format('Y-m-d'));
    }

    /**
     * A hook on a composite owns the whole value, but each date inside it is still held
     * to the shape it documents, at its own pointer.
     *
     * @return iterable<string, array{FieldInterface, mixed, string}>
     */
    public static function hookedComposites(): iterable
    {
        $fill = static fn(mixed $model): mixed => self::fail('The hook ran.');

        yield 'an Obj child' => [
            Obj::make('window')->fields(DateTime::make('startsAt'))->fillUsing($fill)->build(),
            ['startsAt' => '2026-11-05 09:42'],
            '/data/attributes/window/startsAt',
        ];
        yield 'an open Obj member' => [
            Obj::make('marks')->additionalProperties(Date::make('mark'))->fillUsing($fill)->build(),
            ['first' => 'last year'],
            '/data/attributes/marks/first',
        ];
        yield 'a OneOf variant child' => [
            OneOf::make('slot')->discriminator('type')->variant('timed', DateTime::make('startsAt'))->fillUsing($fill)->build(),
            ['type' => 'timed', 'startsAt' => 'last year'],
            '/data/attributes/slot/startsAt',
        ];
        yield 'a Map child' => [
            Map::make('release')->fields(DateTime::make('releasedAt'))->fillUsing($fill)->build(),
            ['releasedAt' => '2026-11-05T09:42:00'],
            '/data/attributes/release/releasedAt',
        ];
        yield 'a structured list element' => [
            ArrayList::make('sources')->of(Obj::make('source')->fields(DateTime::make('retrievedAt')))->fillUsing($fill)->build(),
            [['retrievedAt' => '2026-11-05T09:42:00Z'], ['retrievedAt' => 'last year']],
            '/data/attributes/sources/1/retrievedAt',
        ];
        yield 'a union date member' => [
            Union::make('due')->of(Date::make('day'), Integer::make('days'))->fillUsing($fill)->build(),
            'next week',
            '/data/attributes/due',
        ];
        yield 'a list of unions' => [
            ArrayList::make('marks')->of(Union::make('mark')->of(DateTime::make('at'), Integer::make('n')))->deserializeUsing($fill)->build(),
            [1, '2026-11-05 09:42'],
            '/data/attributes/marks/1',
        ];
        yield 'an Obj nested in a hooked Obj' => [
            Obj::make('plan')->fields(Obj::make('window')->fields(Time::make('opensAt')))->fillUsing($fill)->build(),
            ['window' => ['opensAt' => '9am']],
            '/data/attributes/plan/window/opensAt',
        ];
    }

    #[Test]
    #[DataProvider('hookedComposites')]
    public function aHookedCompositeStillChecksItsDates(FieldInterface $field, mixed $input, string $pointer): void
    {
        $this->assertRefused($field, $input, $pointer);
    }

    #[Test]
    public function aHookedCompositeWithValidDatesReachesTheHook(): void
    {
        $field = Obj::make('window')
            ->fields(DateTime::make('startsAt'), Date::make('day'))
            ->fillUsing(static fn(mixed $model, mixed $value): array => ['raw' => $value])
            ->build();
        $input = ['startsAt' => '2026-11-05T09:42:00Z', 'day' => '2026-11-05'];

        self::assertSame(['raw' => $input], $field->hydrate([], $input, [], StubJsonApiRequest::create(), true));
    }

    private function hydrate(FieldInterface $field, mixed $input): mixed
    {
        $model = $field->hydrate([], $input, [], StubJsonApiRequest::create(), true);
        self::assertIsArray($model);

        return $model[$field->name()] ?? null;
    }

    private function assertRefused(FieldInterface $field, mixed $input, string $pointer): void
    {
        try {
            $field->hydrate([], $input, [], StubJsonApiRequest::create(), true);
        } catch (AttributeValueInvalid $e) {
            $error = $e->getErrors()[0];
            self::assertSame('ATTRIBUTE_VALUE_INVALID', $error->code);
            self::assertSame('422', $error->status);
            self::assertSame($pointer, $error->source?->pointer);

            return;
        }

        self::fail('The value was accepted.');
    }
}
