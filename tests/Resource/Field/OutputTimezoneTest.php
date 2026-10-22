<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\DateTimeBuilder;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DateTime::class)]
#[CoversClass(DateTimeBuilder::class)]
#[CoversClass(Date::class)]
#[CoversClass(Time::class)]
#[CoversClass(Map::class)]
#[CoversClass(Obj::class)]
#[CoversClass(OneOf::class)]
final class OutputTimezoneTest extends TestCase
{
    #[Test]
    public function aDateTimeRendersInItsOutputTimezone(): void
    {
        $field = DateTime::make('at')->outputTimezone('UTC')->build();

        self::assertSame('2024-07-01T10:30:00+00:00', $this->render($field, new \DateTimeImmutable('2024-07-01 12:30:00', new \DateTimeZone('Europe/Paris'))));
    }

    #[Test]
    public function aDateRendersInItsOutputTimezone(): void
    {
        // 00:30 in Paris is still the previous day in UTC: the date moves, which is why
        // a Date converts only when it asks to.
        $field = Date::make('day')->outputTimezone('UTC')->build();

        self::assertSame('2024-06-30', $this->render($field, new \DateTimeImmutable('2024-07-01 00:30:00', new \DateTimeZone('Europe/Paris'))));
    }

    #[Test]
    public function aTimeRendersInItsOutputTimezone(): void
    {
        $field = Time::make('opensAt')->outputTimezone('UTC')->build();

        self::assertSame('07:00:00', $this->render($field, new \DateTimeImmutable('2024-07-01 09:00:00', new \DateTimeZone('Europe/Paris'))));
    }

    #[Test]
    public function withoutAnOutputTimezoneAValueRendersInTheZoneItCarries(): void
    {
        $field = DateTime::make('at')->build();

        self::assertSame('2024-07-01T12:30:00+02:00', $this->render($field, new \DateTimeImmutable('2024-07-01 12:30:00', new \DateTimeZone('Europe/Paris'))));
    }

    #[Test]
    public function aMutableDateTimeOnTheModelIsLeftUnmodified(): void
    {
        $value = new \DateTime('2024-07-01 12:30:00', new \DateTimeZone('Europe/Paris'));

        $rendered = $this->render(DateTime::make('at')->outputTimezone('UTC')->build(), $value);

        self::assertSame('2024-07-01T10:30:00+00:00', $rendered);
        self::assertSame('Europe/Paris', $value->getTimezone()->getName());
        self::assertSame('2024-07-01T12:30:00+02:00', $value->format(\DateTimeInterface::ATOM));
    }

    #[Test]
    public function aNonDateValuePassesThroughUnchanged(): void
    {
        self::assertNull($this->render(DateTime::make('at')->outputTimezone('UTC')->build(), null));
    }

    #[Test]
    public function aDefaultZoneConvertsADateTimeWithNoZoneOfItsOwn(): void
    {
        $field = DateTime::make('at')->build()->withDefaultOutputTimezone('UTC');

        self::assertSame('UTC', $field->outputTimezone());
        self::assertSame('2024-07-01T10:30:00+00:00', $this->render($field, new \DateTimeImmutable('2024-07-01 12:30:00', new \DateTimeZone('Europe/Paris'))));
    }

    #[Test]
    public function aFieldsOwnOutputTimezoneBeatsTheDefault(): void
    {
        $field = DateTime::make('at')->outputTimezone('America/New_York')->build();

        self::assertSame($field, $field->withDefaultOutputTimezone('UTC'));
        self::assertSame('2024-07-01T06:30:00-04:00', $this->render($field, new \DateTimeImmutable('2024-07-01 12:30:00', new \DateTimeZone('Europe/Paris'))));
    }

    #[Test]
    public function aDateAndATimeIgnoreTheDefault(): void
    {
        $date = Date::make('day')->build();
        $time = Time::make('opensAt')->build();

        self::assertSame($date, $date->withDefaultOutputTimezone('UTC'));
        self::assertSame($time, $time->withDefaultOutputTimezone('UTC'));
    }

    #[Test]
    public function compositeFieldsPassTheDefaultToTheirChildren(): void
    {
        $paris = new \DateTimeImmutable('2024-07-01 12:30:00', new \DateTimeZone('Europe/Paris'));

        $map = Map::make('window')->fields(DateTime::make('startsAt'), Date::make('day'))->build()->withDefaultOutputTimezone('UTC');
        self::assertSame(
            ['startsAt' => '2024-07-01T10:30:00+00:00', 'day' => '2024-07-01'],
            $map->serialize(['startsAt' => $paris, 'day' => $paris], StubJsonApiRequest::create(), 'window'),
        );

        $obj = Obj::make('window')->fields(DateTime::make('startsAt'))->build()->withDefaultOutputTimezone('UTC');
        self::assertSame(
            ['startsAt' => '2024-07-01T10:30:00+00:00'],
            $obj->serialize(['window' => ['startsAt' => $paris]], StubJsonApiRequest::create(), 'window'),
        );

        $oneOf = OneOf::make('slot')
            ->variant('timed', DateTime::make('startsAt'))
            ->build()
            ->withDefaultOutputTimezone('UTC');
        self::assertSame(
            ['type' => 'timed', 'startsAt' => '2024-07-01T10:30:00+00:00'],
            $oneOf->serialize(['slot' => ['type' => 'timed', 'startsAt' => $paris]], StubJsonApiRequest::create(), 'slot'),
        );
    }

    #[Test]
    public function aCompositeWithNothingToConvertIsReturnedAsIs(): void
    {
        $map = Map::make('window')->fields(Date::make('day'))->build();

        self::assertSame($map, $map->withDefaultOutputTimezone('UTC'));
    }

    #[Test]
    public function inputTimezoneMovesHydratedValuesLikeTheDeprecatedUseTimezone(): void
    {
        $request = StubJsonApiRequest::create();
        $wire = '2024-07-01T12:30:00+02:00';

        $viaInput = DateTime::make('at')->inputTimezone('UTC')->build()->hydrate(['at' => null], $wire, [], $request, true);
        $viaUse = DateTime::make('at')->useTimezone('UTC')->build()->hydrate(['at' => null], $wire, [], $request, true);

        self::assertIsArray($viaInput);
        self::assertIsArray($viaUse);
        self::assertInstanceOf(\DateTimeImmutable::class, $viaInput['at']);
        self::assertEquals($viaInput, $viaUse);
        self::assertSame('UTC', $viaInput['at']->getTimezone()->getName());
        self::assertSame('2024-07-01T10:30:00+00:00', $viaInput['at']->format(\DateTimeInterface::ATOM));
    }

    #[Test]
    public function inputTimezoneDoesNotConvertOnOutput(): void
    {
        $field = DateTime::make('at')->inputTimezone('UTC')->build();

        self::assertSame('2024-07-01T12:30:00+02:00', $this->render($field, new \DateTimeImmutable('2024-07-01 12:30:00', new \DateTimeZone('Europe/Paris'))));
    }

    /**
     * @return iterable<string, array{\Closure(DateTimeBuilder): mixed, string}>
     */
    public static function invalidZones(): iterable
    {
        yield 'outputTimezone' => [static fn(DateTimeBuilder $b): mixed => $b->outputTimezone('Mars/Olympus_Mons'), 'outputTimezone'];
        yield 'inputTimezone' => [static fn(DateTimeBuilder $b): mixed => $b->inputTimezone('Mars/Olympus_Mons'), 'inputTimezone'];
        yield 'useTimezone' => [static fn(DateTimeBuilder $b): mixed => $b->useTimezone('Mars/Olympus_Mons'), 'inputTimezone'];
        yield 'empty outputTimezone' => [static fn(DateTimeBuilder $b): mixed => $b->outputTimezone(''), 'outputTimezone'];
    }

    /**
     * @param \Closure(DateTimeBuilder): mixed $configure
     */
    #[Test]
    #[DataProvider('invalidZones')]
    public function anInvalidZoneFailsWhenTheFieldIsDeclared(\Closure $configure, string $setting): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($setting . '() was given');

        $configure(DateTime::make('at'));
    }

    #[Test]
    public function anInvalidZoneFailsWhenTheFieldIsConstructedDirectly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DateTime::make('at')->build()->withDefaultOutputTimezone('Not/AZone');
    }

    #[Test]
    public function aLiteralZuluFormatIsDocumentedAsADateTimeOnlyWhenTheFieldRendersInUtc(): void
    {
        // A hard-coded `Z` lies about a Paris value, but it is the truth once the field
        // moves every value into UTC first.
        self::assertNull(DateTime::make('at')->format('Y-m-d\TH:i:s\Z')->build()->schemaFormat());
        self::assertSame('date-time', DateTime::make('at')->format('Y-m-d\TH:i:s\Z')->outputTimezone('UTC')->build()->schemaFormat());
        self::assertSame('date-time', DateTime::make('at')->outputTimezone('UTC')->build()->schemaFormat());
    }

    private function render(DateTime $field, mixed $value): mixed
    {
        return $field->serialize([$field->name() => $value], StubJsonApiRequest::create(), $field->name());
    }
}
