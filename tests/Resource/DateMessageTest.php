<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Resource\DateMessage;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\DateFilterValue;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\GreaterThan;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Response\ErrorRenderer;
use haddowg\JsonApi\Schema\Error\ErrorDetailVariantResolverInterface;
use haddowg\JsonApi\Schema\Error\ErrorMessageResolverInterface;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each kind of date has one message, which a temporal attribute's write, a date range's
 * bound and a date filter's value all report, translatable by its own key.
 */
#[CoversClass(DateMessage::class)]
#[CoversClass(AttributeValueInvalid::class)]
#[CoversClass(FilterValueInvalid::class)]
#[CoversClass(DateTime::class)]
#[CoversClass(DateRange::class)]
#[CoversClass(DateFilterValue::class)]
#[CoversClass(ErrorRenderer::class)]
final class DateMessageTest extends TestCase
{
    #[Test]
    public function eachKindHasOneMessageWithAFixedExample(): void
    {
        self::assertSame('This value is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.', DateMessage::dateTime()->message());
        self::assertSame('This value is not a date like 2026-10-01.', DateMessage::date()->message());
        self::assertSame('This value is not a time like 09:42:00Z.', DateMessage::time()->message());
        self::assertSame('This value is not a date-time like 25/10/2026 14:30.', DateMessage::formattedDateTime('25/10/2026 14:30')->message());
    }

    #[Test]
    public function aRenderedMessageIsRecognisedWithItsVariantAndExample(): void
    {
        foreach ([DateMessage::dateTime(), DateMessage::date('1990-05-21'), DateMessage::time(), DateMessage::formattedDateTime('25/10/2026 14:30')] as $message) {
            self::assertEquals($message, DateMessage::recognise($message->message()));
        }

        self::assertNull(DateMessage::recognise('This value is not valid.'));
    }

    /**
     * @return iterable<string, array{FieldInterface, mixed, string, string}>
     */
    public static function attributes(): iterable
    {
        yield 'date-time' => [DateTime::make('publishedAt')->build(), 'last year', 'This value is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.', '/data/attributes/publishedAt'];
        yield 'date-time, declared example' => [DateTime::make('publishedAt')->example('2026-11-05T09:42:00+01:00')->build(), '2026-11-05', 'This value is not an RFC 3339 date-time with an offset, such as 2026-11-05T09:42:00+01:00.', '/data/attributes/publishedAt'];
        yield 'date' => [Date::make('bornOn')->build(), '2026-02-30', 'This value is not a date like 2026-10-01.', '/data/attributes/bornOn'];
        yield 'date, declared example' => [Date::make('bornOn')->example('1990-05-21')->build(), 'tomorrow', 'This value is not a date like 1990-05-21.', '/data/attributes/bornOn'];
        yield 'time with an offset' => [Time::make('opensAt')->format('H:i:sP')->build(), '09:42:00', 'This value is not a time like 09:42:00Z.', '/data/attributes/opensAt'];
        yield 'time with an offset, declared example' => [Time::make('opensAt')->format('H:i:sP')->example('18:30:00+01:00')->build(), 'noon', 'This value is not a time like 18:30:00+01:00.', '/data/attributes/opensAt'];
        yield 'time in its default format' => [Time::make('opensAt')->build(), '09:42', 'This value is not a time like 14:30:00.', '/data/attributes/opensAt'];
        yield 'time in its default format, declared example' => [Time::make('opensAt')->example('18:30:00')->build(), '09:42', 'This value is not a time like 18:30:00.', '/data/attributes/opensAt'];
        yield 'date-time in a custom format' => [DateTime::make('heldAt')->format('d/m/Y H:i')->build(), '2026-11-05T09:42:00Z', 'This value is not a date-time like 25/10/2026 14:30.', '/data/attributes/heldAt'];
        yield 'date-time in a custom format, declared example' => [DateTime::make('heldAt')->format('d/m/Y H:i')->example('31/12/2026 23:59')->build(), 'soon', 'This value is not a date-time like 31/12/2026 23:59.', '/data/attributes/heldAt'];
        yield 'date in a custom format' => [Date::make('bornOn')->format('d/m/Y')->build(), '1990-05-21', 'This value is not a date like 25/10/2026.', '/data/attributes/bornOn'];
        yield 'nested in an object' => [Obj::make('window')->fields(DateTime::make('startsAt'))->build(), ['startsAt' => 'soon'], 'This value is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.', '/data/attributes/window/startsAt'];
        yield 'nested in a list' => [ArrayList::make('slots')->of(Obj::make('slot')->fields(Date::make('day')))->build(), [['day' => '2026-10-01'], ['day' => '2026-02-30']], 'This value is not a date like 2026-10-01.', '/data/attributes/slots/1/day'];
    }

    #[Test]
    #[DataProvider('attributes')]
    public function anAttributeWriteReportsItsKindsMessageAtItsPointer(FieldInterface $field, mixed $input, string $detail, string $pointer): void
    {
        try {
            $field->hydrate([], $input, [], StubJsonApiRequest::create(), true);
        } catch (AttributeValueInvalid $e) {
            $error = $e->getErrors()[0];
            self::assertSame('ATTRIBUTE_VALUE_INVALID', $error->code);
            self::assertSame('422', $error->status);
            self::assertSame($detail, $error->detail);
            self::assertSame($pointer, $error->source?->pointer);
            self::assertStringStartsWith('ATTRIBUTE_VALUE_INVALID.', $error->detailVariant);

            return;
        }

        self::fail('The value was accepted.');
    }

    #[Test]
    public function aMovedErrorKeepsItsMessageUnderItsNewPointer(): void
    {
        $moved = AttributeValueInvalid::forDate('startsAt', DateMessage::date())->at('window/startsAt');

        self::assertSame('window/startsAt', $moved->attribute);
        self::assertSame('This value is not a date like 2026-10-01.', $moved->getErrors()[0]->detail);
        self::assertSame('ATTRIBUTE_VALUE_INVALID.date', $moved->getErrors()[0]->detailVariant);
        self::assertSame('The value for attribute "tags" is invalid: expected a list', (new AttributeValueInvalid('items', 'expected a list'))->at('tags')->getErrors()[0]->detail);
    }

    /**
     * @return iterable<string, array{DateRange, mixed, ?string}>
     */
    public static function bounds(): iterable
    {
        $range = DateRange::make('published')->build();
        $dateTime = 'This value is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.';
        yield 'a date-time' => [$range, '2026-10-01T00:00:00Z', null];
        yield 'garbage' => [$range, 'banana', $dateTime];
        yield 'a date for a date-time' => [$range, '2026-10-01', $dateTime];
        yield 'an impossible day' => [$range, '2026-02-30T00:00:00Z', $dateTime];
        yield 'a number' => [$range, 20261001, $dateTime];

        $days = DateRange::make('published')->calendarDays()->build();
        yield 'a date over calendar days' => [$days, '2026-10-01', null];
        yield 'a date-time over calendar days' => [$days, '2026-10-01T00:00:00Z', 'This value is not a date like 2026-10-01.'];
        yield 'an impossible day over calendar days' => [$days, '2026-02-30', 'This value is not a date like 2026-10-01.'];
        yield 'over a Date column' => [DateRange::make('published')->build()->withCalendarDays(), '2026-02-30', 'This value is not a date like 2026-10-01.'];

        yield 'a declared example' => [DateRange::make('published')->example('2025-01-01T09:00:00+01:00')->build(), 'soon', 'This value is not an RFC 3339 date-time with an offset, such as 2025-01-01T09:00:00+01:00.'];
        yield 'a declared example of both bounds' => [DateRange::make('published')->calendarDays()->example(['min' => '2025-01-01', 'max' => '2025-12-31'])->build(), 'soon', 'This value is not a date like 2025-01-01.'];
    }

    #[Test]
    #[DataProvider('bounds')]
    public function aDateRangeBoundIsRefusedWithItsKindsMessage(DateRange $range, mixed $bound, ?string $message): void
    {
        self::assertSame($message, $range->invalidBound($bound, 'min')?->message());
    }

    #[Test]
    public function eachBoundTakesItsOwnDeclaredExample(): void
    {
        $range = DateRange::make('published')->calendarDays()->example(['min' => '2025-01-01', 'max' => '2025-12-31'])->build();

        self::assertSame('This value is not a date like 2025-12-31.', $range->invalidBound('soon', 'max')?->message());
        self::assertSame('This value is not a date like 2025-01-01.', DateRange::make('published')->calendarDays()->example(['min' => '2025-01-01'])->build()->invalidBound('soon', 'max')?->message());
    }

    /**
     * @return iterable<string, array{FilterInterface, ?string}>
     */
    public static function filters(): iterable
    {
        yield 'equal' => [Where::make('publishedAt')->build(), 'publishedAt'];
        yield 'not equal' => [Where::make('when', 'publishedAt', '!=')->build(), 'publishedAt'];
        yield 'greater than' => [Where::make('after', 'publishedAt', '>')->build(), 'publishedAt'];
        yield 'the numeric greater-than preset' => [GreaterThan::make('after', 'publishedAt')->build(), null];
        yield 'in' => [WhereIn::make('days', 'bornOn')->build(), 'bornOn'];
        yield 'not in' => [WhereNotIn::make('notDays', 'bornOn')->build(), 'bornOn'];
        yield 'a substring match' => [Contains::make('like', 'publishedAt')->build(), null];
        yield 'its own constraints' => [Where::make('publishedAt')->pattern('^\d+$')->build(), null];
        yield 'not a date column' => [Where::make('title')->build(), null];
        yield 'an unknown column' => [Where::make('other')->build(), null];
    }

    #[Test]
    #[DataProvider('filters')]
    public function aComparisonOrListFilterOverADateColumnReadsThatDate(FilterInterface $filter, ?string $field): void
    {
        $fields = [DateTime::make('publishedAt')->build(), Date::make('bornOn')->build(), Str::make('title')->build()];

        self::assertSame($field, DateFilterValue::fieldFor($filter, $fields)?->name());
    }

    #[Test]
    public function aFilterValueIsRefusedWithItsFieldsMessage(): void
    {
        $field = Date::make('bornOn')->example('1990-05-21')->build();

        self::assertNull(DateFilterValue::invalid($field, '2026-10-01'));
        self::assertSame('This value is not a date like 1990-05-21.', DateFilterValue::invalid($field, '2026-02-30')?->message());
        self::assertSame('This value is not a date like 1990-05-21.', DateFilterValue::invalid($field, 7)?->message());
    }

    #[Test]
    public function aFilterValueErrorCarriesItsTranslationKeyAndExample(): void
    {
        $errors = (new FilterValueInvalid('published', [DateMessage::date(), 'This value is not valid.']))->getErrors();

        self::assertSame('This value is not a date like 2026-10-01.', $errors[0]->detail);
        self::assertSame('FILTER_VALUE_INVALID.date', $errors[0]->detailVariant);
        self::assertSame(['example' => '2026-10-01'], $errors[0]->context);
        self::assertSame('filter[published]', $errors[0]->source?->parameter);
        self::assertSame('This value is not valid.', $errors[1]->detail);
        self::assertSame('', $errors[1]->detailVariant);
    }

    #[Test]
    public function aVariantResolverTranslatesTheDateMessageWithItsExample(): void
    {
        $resolver = new class implements ErrorDetailVariantResolverInterface {
            public function title(string $code): ?string
            {
                return null;
            }

            public function detail(string $code): ?string
            {
                return $code === 'ATTRIBUTE_VALUE_INVALID' ? 'Valeur invalide.' : null;
            }

            public function detailVariant(string $variant): ?string
            {
                return $variant === 'ATTRIBUTE_VALUE_INVALID.date' ? 'Ce n\'est pas une date comme {example}.' : null;
            }
        };

        $date = AttributeValueInvalid::forDate('bornOn', DateMessage::date('1990-05-21'))->getErrors()[0];
        $time = AttributeValueInvalid::forDate('opensAt', DateMessage::time())->getErrors()[0];

        self::assertSame('Ce n\'est pas une date comme 1990-05-21.', ErrorRenderer::error($date, $resolver)['detail']);
        // A variant the resolver has no translation for falls back to the code's own.
        self::assertSame('Valeur invalide.', ErrorRenderer::error($time, $resolver)['detail']);
    }

    #[Test]
    public function anErrorMovedToAnotherSourceKeepsItsVariantAndContext(): void
    {
        $error = (new FilterValueInvalid('published', [DateMessage::date()]))->getErrors()[0]
            ->withSource(\haddowg\JsonApi\Schema\Error\ErrorSource::fromPointer('/atomic:operations/0'));

        self::assertSame('FILTER_VALUE_INVALID.date', $error->detailVariant);
        self::assertSame(['example' => '2026-10-01'], $error->context);
        self::assertSame('/atomic:operations/0', $error->source?->pointer);
    }

    #[Test]
    public function aResolverWithoutVariantsKeepsTranslatingByCode(): void
    {
        $resolver = new class implements ErrorMessageResolverInterface {
            public function title(string $code): ?string
            {
                return null;
            }

            public function detail(string $code): ?string
            {
                return $code === 'FILTER_VALUE_INVALID' ? 'Filtre invalide, par exemple {example}.' : null;
            }
        };

        $error = (new FilterValueInvalid('published', [DateMessage::dateTime()]))->getErrors()[0];

        self::assertSame('Filtre invalide, par exemple 2026-10-01T00:00:00Z.', ErrorRenderer::error($error, $resolver)['detail']);
        self::assertSame('This value is not an RFC 3339 date-time with an offset, such as 2026-10-01T00:00:00Z.', ErrorRenderer::error($error)['detail']);
    }
}
