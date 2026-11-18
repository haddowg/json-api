<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Resource\DateTimeParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DateTimeParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validValues(): iterable
    {
        yield 'date' => ['2024-02-29', '2024-02-29T00:00:00+00:00'];
        yield 'date-time Z' => ['2026-10-01T15:00:00Z', '2026-10-01T15:00:00+00:00'];
        yield 'date-time with offset' => ['2026-10-01T11:00:00-04:00', '2026-10-01T11:00:00-04:00'];
        yield 'compact offset' => ['2026-10-01T11:00:00+0100', '2026-10-01T11:00:00+01:00'];
        yield 'fraction' => ['2026-10-01T11:00:00.123456Z', '2026-10-01T11:00:00+00:00'];
        yield 'space separator, no seconds' => ['2026-10-01 11:00', '2026-10-01T11:00:00+00:00'];
        yield 'last day of a 31-day month' => ['2026-12-31T23:59:59Z', '2026-12-31T23:59:59+00:00'];
    }

    #[Test]
    #[DataProvider('validValues')]
    public function parsesARealDate(string $value, string $expected): void
    {
        self::assertSame($expected, DateTimeParser::parse($value, new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function impossibleValues(): iterable
    {
        yield '30 February' => ['2026-02-30'];
        yield '30 February with time' => ['2026-02-30T00:00:00Z'];
        yield '29 February outside a leap year' => ['2026-02-29'];
        yield '31 April' => ['2026-04-31T10:00Z'];
        yield 'hour 24' => ['2026-02-28T24:00:00Z'];
        yield 'month 13' => ['1997-13-99'];
        yield 'minute 60' => ['2026-02-28T23:60:00Z'];
        yield 'garbage' => ['banana'];
    }

    #[Test]
    #[DataProvider('impossibleValues')]
    public function rejectsAnImpossibleOrUnparseableValue(string $value): void
    {
        self::assertNull(DateTimeParser::tryParse($value));

        $this->expectException(\InvalidArgumentException::class);
        DateTimeParser::parse($value);
    }

    #[Test]
    public function tryParseAnswersNullForBlankAndNonStringValues(): void
    {
        self::assertNull(DateTimeParser::tryParse(''));
        self::assertNull(DateTimeParser::tryParse(null));
        self::assertNull(DateTimeParser::tryParse(20260101));
    }

    #[Test]
    public function aRejectionNamesTheWarning(): void
    {
        try {
            DateTimeParser::parse('2026-02-30');
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('2026-02-30', $e->getMessage());
            self::assertStringContainsString('The parsed date was invalid', $e->getMessage());
        }
    }

    #[Test]
    public function aWarningFromAnEarlierParseDoesNotLeakIntoALaterOne(): void
    {
        self::assertNull(DateTimeParser::tryParse('2026-02-30'));
        self::assertNotNull(DateTimeParser::tryParse('2026-02-28'));
    }

    /**
     * @return iterable<string, array{\Closure(string): \DateTimeImmutable, string, non-empty-string}>
     */
    public static function strictAccepted(): iterable
    {
        $dateTime = DateTimeParser::parseDateTime(...);
        yield 'date-time Z' => [$dateTime, '2026-11-05T09:42:00Z', '2026-11-05T09:42:00.000000+00:00'];
        yield 'date-time offset' => [$dateTime, '2026-11-05T09:42:00+01:00', '2026-11-05T09:42:00.000000+01:00'];
        yield 'date-time fraction' => [$dateTime, '2026-11-05T09:42:00.123Z', '2026-11-05T09:42:00.123000+00:00'];

        $date = static fn(string $value): \DateTimeImmutable => DateTimeParser::parseDate($value, new \DateTimeZone('UTC'));
        yield 'full-date' => [$date, '2026-11-05', '2026-11-05T00:00:00.000000+00:00'];

        $time = DateTimeParser::parseTime(...);
        yield 'full-time Z' => [$time, '09:42:00Z', 'T09:42:00.000000+00:00'];
        yield 'full-time offset and fraction' => [$time, '09:42:00.5-05:00', 'T09:42:00.500000-05:00'];

        $format = static fn(string $value): \DateTimeImmutable => DateTimeParser::parseFormat($value, 'd/m/Y H:i', new \DateTimeZone('UTC'));
        yield 'format, read as written' => [$format, '03/11/2026 09:42', '2026-11-03T09:42:00.000000+00:00'];
    }

    /**
     * @param \Closure(string): \DateTimeImmutable $parse
     * @param non-empty-string                    $expected
     */
    #[Test]
    #[DataProvider('strictAccepted')]
    public function aStrictFormParsesItsShape(\Closure $parse, string $value, string $expected): void
    {
        self::assertStringEndsWith($expected, $parse($value)->format('Y-m-d\TH:i:s.uP'));
    }

    /**
     * @return iterable<string, array{\Closure(string): \DateTimeImmutable, string}>
     */
    public static function strictRefused(): iterable
    {
        $dateTime = DateTimeParser::parseDateTime(...);
        yield 'date-time relative' => [$dateTime, 'last year'];
        yield 'date-time no offset' => [$dateTime, '2026-11-05T09:42:00'];
        yield 'date-time space, no offset' => [$dateTime, '2026-11-05 09:42'];
        yield 'date-time date only' => [$dateTime, '2026-11-05'];
        yield 'date-time impossible' => [$dateTime, '2026-02-30T09:42:00Z'];

        $date = static fn(string $value): \DateTimeImmutable => DateTimeParser::parseDate($value);
        yield 'full-date with a time' => [$date, '2026-11-05T00:00:00Z'];
        yield 'full-date relative' => [$date, 'today'];
        yield 'full-date impossible' => [$date, '2026-02-30'];

        $time = DateTimeParser::parseTime(...);
        yield 'full-time no offset' => [$time, '09:42:00'];
        yield 'full-time no seconds' => [$time, '09:42Z'];

        $format = static fn(string $value): \DateTimeImmutable => DateTimeParser::parseFormat($value, 'd/m/Y H:i');
        yield 'format, another shape' => [$format, '2026-11-03T09:42:00Z'];
        yield 'format, trailing text' => [$format, '03/11/2026 09:42 tomorrow'];
        yield 'format, impossible' => [$format, '31/04/2026 09:42'];
    }

    /**
     * @param \Closure(string): \DateTimeImmutable $parse
     */
    #[Test]
    #[DataProvider('strictRefused')]
    public function aStrictFormRefusesAnyOtherShape(\Closure $parse, string $value): void
    {
        try {
            $parse($value);
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($value, $e->getMessage());

            return;
        }

        self::fail('The value was accepted.');
    }
}
