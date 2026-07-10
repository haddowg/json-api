<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Boolean::class)]
#[CoversClass(DateTime::class)]
#[CoversClass(Decimal::class)]
#[CoversClass(Integer::class)]
#[CoversClass(Str::class)]
#[CoversClass(\haddowg\JsonApi\Resource\Field\AbstractField::class)]
final class CastWireValueTest extends TestCase
{
    #[Test]
    public function strPassesTheValueThroughUnchanged(): void
    {
        $field = Str::make('title')->build();

        self::assertSame('hello', $field->castWireValue('hello'));
        self::assertNull($field->castWireValue(null));
    }

    #[Test]
    public function integerCastsANumericWireValue(): void
    {
        $field = Integer::make('count')->build();

        self::assertSame(9, $field->castWireValue('9'));
        self::assertSame(9, $field->castWireValue(9));
        // A non-numeric value passes through unchanged — type validity is a
        // validation concern, not the cast's.
        self::assertSame('abc', $field->castWireValue('abc'));
    }

    #[Test]
    public function decimalCastsANumericWireValue(): void
    {
        $field = Decimal::make('price')->build();

        self::assertSame(2.0, $field->castWireValue('2'));
        self::assertSame(1.5, $field->castWireValue(1.5));
    }

    #[Test]
    public function booleanCastsAWireValue(): void
    {
        $field = Boolean::make('active')->build();

        self::assertFalse($field->castWireValue(0));
        self::assertTrue($field->castWireValue(1));
        self::assertTrue($field->castWireValue(true));
    }

    #[Test]
    public function dateTimeParsesAWireString(): void
    {
        $field = DateTime::make('publishedAt')->build();

        $cast = $field->castWireValue('2021-06-07T08:09:10+00:00');
        self::assertInstanceOf(\DateTimeImmutable::class, $cast);
        self::assertSame('2021-06-07T08:09:10+00:00', $cast->format(\DateTimeInterface::ATOM));
    }

    #[Test]
    public function dateTimeRejectsAGarbageStringWithATyped422(): void
    {
        $this->expectException(AttributeValueInvalid::class);

        DateTime::make('publishedAt')->build()->castWireValue('banana');
    }

    #[Test]
    public function castMatchesWhatHydrationWouldStore(): void
    {
        // The public cast and the hydrate path store the same domain value —
        // castWireValue IS the hydration value cast, exposed.
        $request = new StubJsonApiRequest();

        $integer = Integer::make('count')->build();
        $model = $integer->hydrate(['count' => 0], '9', [], $request, true);
        self::assertIsArray($model);
        self::assertSame($model['count'], $integer->castWireValue('9'));

        $dateTime = DateTime::make('publishedAt')->build();
        $model = $dateTime->hydrate(['publishedAt' => null], '2021-06-07T08:09:10+00:00', [], $request, true);
        self::assertIsArray($model);
        self::assertEquals($model['publishedAt'], $dateTime->castWireValue('2021-06-07T08:09:10+00:00'));
    }

    #[Test]
    public function castDoesNotConsultTheDeserializeUsingHook(): void
    {
        // The hydrate hooks need the full document context; the public cast is
        // the declared type's value cast alone.
        $field = Str::make('name')->deserializeUsing(fn(mixed $value): mixed => \is_string($value) ? trim($value) : $value)->build();

        self::assertSame('  bob  ', $field->castWireValue('  bob  '));
    }
}
