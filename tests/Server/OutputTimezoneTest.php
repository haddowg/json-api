<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Server\ResourceRegistry;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The server's default output zone, rendered end to end through {@see Server::handle()}:
 * primary data, an included resource and an attribute flattened from a related model all
 * convert, a `Date` and a `Time` do not, and a field's own zone wins.
 */
#[CoversClass(Server::class)]
#[CoversClass(ResourceRegistry::class)]
#[CoversClass(AbstractResource::class)]
final class OutputTimezoneTest extends TestCase
{
    #[Test]
    public function theServerDefaultConvertsDateTimesButLeavesDatesAndTimesAlone(): void
    {
        $body = $this->fetch(Server::make()->withOutputTimezone('UTC'));

        self::assertSame(
            [
                // Paris 12:30 (+02:00) in UTC.
                'startsAt' => '2024-07-01T10:30:00+00:00',
                // The field's own zone beats the server's.
                'announcedAt' => '2024-07-01T06:30:00-04:00',
                // 00:30 in Paris would be the previous day in UTC; the Date is left alone.
                'day' => '2024-07-01',
                'opensAt' => '09:00:00',
                // A Date that asks for UTC itself still converts, and moves a day.
                'utcDay' => '2024-06-30',
                // Flattened from the related venue with on('venue').
                'venueOpenedAt' => '2024-01-15T08:00:00+00:00',
                'window' => ['endsAt' => '2024-07-01T12:00:00+00:00'],
            ],
            $body['data']['attributes'],
        );

        self::assertSame(['openedAt' => '2024-01-15T08:00:00+00:00'], $body['included'][0]['attributes']);
    }

    #[Test]
    public function aFieldCanAskForADifferentZoneFromTheServer(): void
    {
        $body = $this->fetch(Server::make()->withOutputTimezone('Asia/Tokyo'));

        self::assertSame('2024-07-01T19:30:00+09:00', $body['data']['attributes']['startsAt']);
        self::assertSame('2024-07-01T06:30:00-04:00', $body['data']['attributes']['announcedAt']);
    }

    #[Test]
    public function withNoDefaultEachValueRendersInTheZoneItCarries(): void
    {
        $body = $this->fetch(Server::make());

        self::assertSame('2024-07-01T12:30:00+02:00', $body['data']['attributes']['startsAt']);
        self::assertSame('2024-07-01T06:30:00-04:00', $body['data']['attributes']['announcedAt']);
        self::assertSame('2024-01-15T09:00:00+01:00', $body['data']['attributes']['venueOpenedAt']);
        self::assertSame(['openedAt' => '2024-01-15T09:00:00+01:00'], $body['included'][0]['attributes']);
    }

    #[Test]
    public function passingNullClearsTheDefault(): void
    {
        $server = Server::make()->withOutputTimezone('UTC')->withOutputTimezone(null);

        self::assertNull($server->outputTimezone());
        self::assertSame('2024-07-01T12:30:00+02:00', $this->fetch($server)['data']['attributes']['startsAt']);
    }

    #[Test]
    public function theDefaultIsReadableOffTheServerAndLeavesTheOriginalUntouched(): void
    {
        $original = Server::make();
        $zoned = $original->withOutputTimezone('UTC');

        self::assertSame('UTC', $zoned->outputTimezone());
        self::assertNull($original->outputTimezone());
    }

    #[Test]
    public function anInvalidZoneFailsWhenTheServerIsConfigured(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('withOutputTimezone() was given "Mars/Olympus_Mons"');

        Server::make()->withOutputTimezone('Mars/Olympus_Mons');
    }

    /**
     * @return array{data: array{attributes: array<string, mixed>}, included: list<array{attributes: array<string, mixed>}>}
     */
    private function fetch(Server $server): array
    {
        $psr17 = new Psr17Factory();

        $handler = new class implements OperationHandlerInterface {
            public function handle(JsonApiOperationInterface $operation): DataResponse
            {
                $paris = new \DateTimeZone('Europe/Paris');

                $venue = new \stdClass();
                $venue->id = '7';
                $venue->openedAt = new \DateTimeImmutable('2024-01-15 09:00:00', $paris);

                $event = new \stdClass();
                $event->id = '1';
                $event->startsAt = new \DateTimeImmutable('2024-07-01 12:30:00', $paris);
                $event->announcedAt = new \DateTimeImmutable('2024-07-01 12:30:00', $paris);
                $event->day = new \DateTimeImmutable('2024-07-01 00:30:00', $paris);
                $event->opensAt = new \DateTimeImmutable('2024-07-01 09:00:00', $paris);
                $event->utcDay = new \DateTimeImmutable('2024-07-01 00:30:00', $paris);
                $event->window = ['endsAt' => new \DateTimeImmutable('2024-07-01 14:00:00', $paris)];
                $event->venue = $venue;

                return DataResponse::fromResource($event, $operation->context()->server->serializerFor('events'));
            }
        };

        $response = $server
            ->register(OutputTimezoneEventResource::class)
            ->register(OutputTimezoneVenueResource::class)
            ->withPsr17($psr17, $psr17)
            ->withHandler($handler)
            ->handle(
                (new ServerRequest('GET', '/events/1?include=venue'))
                    ->withQueryParams(['include' => 'venue'])
                    ->withAttribute(Target::class, new Target('events', '1')),
            );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array{data: array{attributes: array<string, mixed>}, included: list<array{attributes: array<string, mixed>}>} $body */
        $body = \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);

        return $body;
    }
}

final class OutputTimezoneEventResource extends AbstractResource
{
    public static string $type = 'events';

    public function fields(): array
    {
        return [
            Id::make(),
            DateTime::make('startsAt'),
            DateTime::make('announcedAt')->outputTimezone('America/New_York'),
            Date::make('day'),
            Time::make('opensAt'),
            Date::make('utcDay')->outputTimezone('UTC'),
            DateTime::make('venueOpenedAt')->on('venue')->storedAs('openedAt'),
            Obj::make('window')->fields(DateTime::make('endsAt')),
            BelongsTo::make('venue', 'venues'),
        ];
    }
}

final class OutputTimezoneVenueResource extends AbstractResource
{
    public static string $type = 'venues';

    public function fields(): array
    {
        return [
            Id::make(),
            DateTime::make('openedAt'),
        ];
    }
}
