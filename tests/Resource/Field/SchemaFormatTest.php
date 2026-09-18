<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Time;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * {@see DateTime::schemaFormat()} decides whether the generated schemas may claim
 * `date-time` / `date` / `time`, all three of which are RFC 3339 productions. It is the
 * one place in the library that turns a configurable PHP format string into a promise
 * about the wire, so the accepted and rejected sets are both pinned here.
 */
#[CoversClass(DateTime::class)]
#[CoversClass(Date::class)]
#[CoversClass(Time::class)]
final class SchemaFormatTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function formats(): iterable
    {
        yield 'ATOM (the DateTime default)' => [\DateTimeInterface::ATOM, 'date-time'];

        yield 'RFC 3339' => [\DateTimeInterface::RFC3339, 'date-time'];

        yield 'W3C' => [\DateTimeInterface::W3C, 'date-time'];

        yield 'RFC 3339 with milliseconds' => [\DateTimeInterface::RFC3339_EXTENDED, 'date-time'];

        yield 'hand-rolled with microseconds' => ['Y-m-d\TH:i:s.uP', 'date-time'];

        yield 'p, which writes Z for UTC' => ['Y-m-d\TH:i:sp', 'date-time'];

        yield 'Y-m-d (the Date default)' => ['Y-m-d', 'date'];

        yield 'H:i:sP' => ['H:i:sP', 'time'];

        yield 'H:i:s with milliseconds and an offset' => ['H:i:s.vP', 'time'];

        // RFC 3339 full-time requires a time-offset, so the Time default has never
        // satisfied `format: time`.
        yield 'H:i:s (the Time default) carries no offset' => ['H:i:s', null];

        yield 'H:i drops the seconds' => ['H:i', null];

        yield 'RFC 2822' => [\DateTimeInterface::RFC2822, null];

        yield 'COOKIE' => [\DateTimeInterface::COOKIE, null];

        yield 'a space instead of the T separator' => ['Y-m-d H:i:s', null];

        yield 'no offset at all' => ['Y-m-d\TH:i:s', null];

        yield 'O, whose offset has no colon' => ['Y-m-d\TH:i:sO', null];

        // An abbreviation and a zone identifier are both open sets that move with the
        // tzdata release, and neither is RFC 3339.
        yield 'T, a timezone abbreviation' => ['Y-m-d\TH:i:sT', null];

        yield 'e, a timezone identifier' => ['Y-m-d\TH:i:se', null];

        // RFC-3339-shaped but a lie about the instant: the literal Z claims UTC for a
        // value that may be in any zone.
        yield 'a hard-coded Z' => ['Y-m-d\TH:i:s\Z', null];

        yield 'a hard-coded Z on a time' => ['H:i:s\Z', null];

        // Also RFC-3339-shaped, until the clock passes noon.
        yield 'h, a 12-hour clock' => ['Y-m-d\Th:i:sP', null];

        yield 'n and j, which drop the leading zeros' => ['Y-n-j\TH:i:sP', null];

        yield 'G, an unpadded hour' => ['Y-m-d\TG:i:sP', null];

        yield 'y, a two-digit year' => ['y-m-d\TH:i:sP', null];

        yield 'a Unix timestamp' => ['U', null];

        yield 'a day/month/year shape' => ['d/m/Y H:i', null];
    }

    #[Test]
    #[DataProvider('formats')]
    public function theKeywordFollowsWhatTheFormatActuallyWrites(string $format, ?string $expected): void
    {
        self::assertSame($expected, DateTime::make('x')->format($format)->build()->schemaFormat());
    }

    /**
     * The answer comes from the format string, not from the field's class, so a
     * misconfigured subclass reports the shape it really writes.
     */
    #[Test]
    public function theFieldClassDoesNotDecideTheKeyword(): void
    {
        self::assertSame('date', Date::make('d')->build()->schemaFormat());
        self::assertNull(Time::make('t')->build()->schemaFormat());

        self::assertSame('date-time', Date::make('d')->format(\DateTimeInterface::ATOM)->build()->schemaFormat());
        self::assertSame('date', Time::make('t')->format('Y-m-d')->build()->schemaFormat());
    }

    #[Test]
    public function theSampleWireValueRendersTheConfiguredFormat(): void
    {
        self::assertSame('05/09/2024 09:08', DateTime::make('x')->format('d/m/Y H:i')->build()->sampleWireValue());
        self::assertSame('2024-09-05', Date::make('d')->build()->sampleWireValue());
        self::assertSame('09:08:07', Time::make('t')->build()->sampleWireValue());
    }

    /**
     * A format is classified by rendering it, so a rendering nothing can read must fall
     * through to "no standard keyword" rather than escape as a parse error — including
     * the awkward case of literal text that passes the shape check (an impossible
     * offset) and then fails to parse.
     */
    #[Test]
    public function anUnreadableRenderingIsSimplyNotAStandardFormat(): void
    {
        self::assertNull(DateTime::make('x')->format('\b\a\n\a\n\a')->build()->schemaFormat());
        self::assertNull(DateTime::make('x')->format('Y-m-d\TH:i:s+99:99')->build()->schemaFormat());
    }
}
