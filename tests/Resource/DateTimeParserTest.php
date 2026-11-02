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
}
