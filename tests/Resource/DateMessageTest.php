<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Resource\DateMessage;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each kind of date has one message, which a temporal attribute's write and a date range's
 * bound both report.
 */
#[CoversClass(DateMessage::class)]
#[CoversClass(AttributeValueInvalid::class)]
#[CoversClass(DateTime::class)]
#[CoversClass(DateRange::class)]
final class DateMessageTest extends TestCase
{
    #[Test]
    public function eachKindHasOneMessageWithAFixedExample(): void
    {
        self::assertSame('at is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.', DateMessage::dateTime()->for('at'));
        self::assertSame('at is not a date like 2026-10-01.', DateMessage::date()->for('at'));
        self::assertSame('at is not a time like 09:42:00Z.', DateMessage::time()->for('at'));
        self::assertSame('at is not a date-time like 05/09/2024 09:08.', DateMessage::dateTimeLike('05/09/2024 09:08')->for('at'));
    }

    /**
     * @return iterable<string, array{FieldInterface, mixed, string}>
     */
    public static function attributes(): iterable
    {
        yield 'date-time' => [DateTime::make('publishedAt')->build(), 'last year', 'publishedAt is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.'];
        yield 'date-time, declared example' => [DateTime::make('publishedAt')->example('2026-11-05T09:42:00+01:00')->build(), '2026-11-05', 'publishedAt is not an RFC 3339 date-time with an offset, such as 2026-11-05T09:42:00+01:00.'];
        yield 'date' => [Date::make('bornOn')->build(), '2026-02-30', 'bornOn is not a date like 2026-10-01.'];
        yield 'date, declared example' => [Date::make('bornOn')->example('1990-05-21')->build(), 'tomorrow', 'bornOn is not a date like 1990-05-21.'];
        yield 'time with an offset' => [Time::make('opensAt')->format('H:i:sP')->build(), '09:42:00', 'opensAt is not a time like 09:42:00Z.'];
        yield 'time with an offset, declared example' => [Time::make('opensAt')->format('H:i:sP')->example('18:30:00+01:00')->build(), 'noon', 'opensAt is not a time like 18:30:00+01:00.'];
        yield 'time in its default format' => [Time::make('opensAt')->build(), '09:42', 'opensAt is not a time like 09:08:07.'];
        yield 'time in its default format, declared example' => [Time::make('opensAt')->example('18:30:00')->build(), '09:42', 'opensAt is not a time like 18:30:00.'];
        yield 'date-time in a custom format' => [DateTime::make('heldAt')->format('d/m/Y H:i')->build(), '2026-11-05T09:42:00Z', 'heldAt is not a date-time like 05/09/2024 09:08.'];
        yield 'date in a custom format' => [Date::make('bornOn')->format('d/m/Y')->build(), '1990-05-21', 'bornOn is not a date like 05/09/2024.'];
        yield 'nested in an object' => [Obj::make('window')->fields(DateTime::make('startsAt'))->build(), ['startsAt' => 'soon'], 'window/startsAt is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.'];
        yield 'nested in a list' => [ArrayList::make('slots')->of(Obj::make('slot')->fields(Date::make('day')))->build(), [['day' => '2026-10-01'], ['day' => '2026-02-30']], 'slots/1/day is not a date like 2026-10-01.'];
    }

    #[Test]
    #[DataProvider('attributes')]
    public function anAttributeWriteReportsItsKindsMessageAtItsPointer(FieldInterface $field, mixed $input, string $detail): void
    {
        try {
            $field->hydrate([], $input, [], StubJsonApiRequest::create(), true);
        } catch (AttributeValueInvalid $e) {
            $error = $e->getErrors()[0];
            self::assertSame('ATTRIBUTE_VALUE_INVALID', $error->code);
            self::assertSame('422', $error->status);
            self::assertSame($detail, $error->detail);
            self::assertSame('/data/attributes/' . \explode(' ', $detail)[0], $error->source?->pointer);

            return;
        }

        self::fail('The value was accepted.');
    }

    #[Test]
    public function aMovedErrorKeepsItsMessageUnderItsNewPointer(): void
    {
        $moved = AttributeValueInvalid::forDate('startsAt', DateMessage::date())->at('window/startsAt');

        self::assertSame('window/startsAt', $moved->attribute);
        self::assertSame('window/startsAt is not a date like 2026-10-01.', $moved->getErrors()[0]->detail);
        self::assertSame('The value for attribute "tags" is invalid: expected a list', (new AttributeValueInvalid('items', 'expected a list'))->at('tags')->getErrors()[0]->detail);
    }

    /**
     * @return iterable<string, array{DateRange, mixed, ?string}>
     */
    public static function bounds(): iterable
    {
        $range = DateRange::make('published')->build();
        yield 'a date-time' => [$range, '2026-10-01T00:00:00Z', null];
        yield 'garbage' => [$range, 'banana', 'filter[published][min] is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.'];
        yield 'a date for a date-time' => [$range, '2026-10-01', 'filter[published][min] is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.'];
        yield 'an impossible day' => [$range, '2026-02-30T00:00:00Z', 'filter[published][min] is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.'];
        yield 'a number' => [$range, 20261001, 'filter[published][min] is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.'];

        $days = DateRange::make('published')->calendarDays()->build();
        yield 'a date over calendar days' => [$days, '2026-10-01', null];
        yield 'a date-time over calendar days' => [$days, '2026-10-01T00:00:00Z', 'filter[published][min] is not a date like 2026-10-01.'];
        yield 'an impossible day over calendar days' => [$days, '2026-02-30', 'filter[published][min] is not a date like 2026-10-01.'];
        yield 'over a Date column' => [DateRange::make('published')->build()->withCalendarDays(), '2026-02-30', 'filter[published][min] is not a date like 2026-10-01.'];

        yield 'a declared example' => [DateRange::make('published')->example('2025-01-01T09:00:00+01:00')->build(), 'soon', 'filter[published][min] is not an RFC 3339 date-time with an offset, such as 2025-01-01T09:00:00+01:00.'];
        yield 'a declared example of both bounds' => [DateRange::make('published')->calendarDays()->example(['min' => '2025-01-01', 'max' => '2025-12-31'])->build(), 'soon', 'filter[published][min] is not a date like 2025-01-01.'];
    }

    #[Test]
    #[DataProvider('bounds')]
    public function aDateRangeBoundIsRefusedWithItsKindsMessage(DateRange $range, mixed $bound, ?string $message): void
    {
        self::assertSame($message, $range->invalidBound($bound, 'min', 'filter[published][min]'));
    }

    #[Test]
    public function eachBoundTakesItsOwnDeclaredExample(): void
    {
        $range = DateRange::make('published')->calendarDays()->example(['min' => '2025-01-01', 'max' => '2025-12-31'])->build();

        self::assertSame('filter[published][max] is not a date like 2025-12-31.', $range->invalidBound('soon', 'max', 'filter[published][max]'));
        self::assertSame('filter[published][max] is not a date like 2025-01-01.', DateRange::make('published')->calendarDays()->example(['min' => '2025-01-01'])->build()->invalidBound('soon', 'max', 'filter[published][max]'));
    }
}
