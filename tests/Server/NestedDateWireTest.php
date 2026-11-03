<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A nested date child stores a string, and the create response and a re-read through a
 * JSON column render it exactly as the `\DateTimeImmutable` it used to store rendered,
 * whenever an output zone applies.
 */
#[CoversClass(DateTime::class)]
#[CoversClass(Obj::class)]
final class NestedDateWireTest extends TestCase
{
    private string $defaultZone;

    protected function setUp(): void
    {
        $this->defaultZone = \date_default_timezone_get();
        \date_default_timezone_set('Europe/Paris');
    }

    protected function tearDown(): void
    {
        \date_default_timezone_set($this->defaultZone);
    }

    private const string BODY = '{"data":{"type":"nested-date-events","attributes":{'
        . '"window":{"startsAt":"2026-11-03T17:52:53+02:00","endsAt":"2026-11-03T18:30:00+02:00","day":"2026-11-03","opensAt":"09:15:00"},'
        . '"slots":[{"type":"timed","at":"2026-11-03T10:00:00-05:00"}]}}}';

    /**
     * @return iterable<string, array{?string}>
     */
    public static function serverZones(): iterable
    {
        yield 'a UTC server default' => ['UTC'];
        yield 'a non-UTC server default' => ['Asia/Tokyo'];
    }

    #[Test]
    #[DataProvider('serverZones')]
    public function theCreateResponseAndAReReadRenderAsTheStoredObjectDid(string $zone): void
    {
        $server = Server::make()->withOutputTimezone($zone);
        [$created, $stored] = $this->create($server);

        $legacy = $this->legacyModel();
        $expected = $this->read($server, $legacy);

        self::assertSame($expected, $created);
        self::assertSame($expected, $this->read($server, $stored));
        // What the object left in a JSON column, as PHP's dump.
        self::assertSame($expected, $this->read($server, self::throughJsonColumn($legacy)));
    }

    /**
     * The zone a top-level date-time from a naive column renders in.
     */
    #[Test]
    public function withNoOutputZoneANestedDateTimeRendersInTheDefaultZone(): void
    {
        $server = Server::make();
        [$created, $stored] = $this->create($server);

        $expected = [
            'window' => [
                'startsAt' => '2026-11-03T16:52:53+01:00',
                // The field's own outputTimezone still applies.
                'endsAt' => '2026-11-03T11:30:00-05:00',
                'day' => '2026-11-03',
                'opensAt' => '09:15:00',
            ],
            'slots' => [['type' => 'timed', 'at' => '2026-11-03T16:00:00+01:00']],
        ];

        self::assertSame($expected, $created);
        self::assertSame($expected, $this->read($server, $stored));
        $legacy = $this->read($server, $this->legacyModel());
        self::assertIsArray($legacy['window'] ?? null);
        self::assertSame($expected['window']['endsAt'], $legacy['window']['endsAt'] ?? null);
    }

    /**
     * What hydration stored before: each nested date as the `\DateTimeImmutable` parsed
     * from the request.
     */
    private function legacyModel(): \stdClass
    {
        return (object) [
            'id' => '1',
            'window' => [
                'startsAt' => new \DateTimeImmutable('2026-11-03T17:52:53+02:00'),
                'endsAt' => new \DateTimeImmutable('2026-11-03T18:30:00+02:00'),
                'day' => new \DateTimeImmutable('2026-11-03'),
                'opensAt' => new \DateTimeImmutable('09:15:00'),
            ],
            'slots' => [['type' => 'timed', 'at' => new \DateTimeImmutable('2026-11-03T10:00:00-05:00')]],
        ];
    }

    private static function throughJsonColumn(\stdClass $model): \stdClass
    {
        return (object) \json_decode(\json_encode($model, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * Posts {@see BODY}, answering the attributes of the create response and the model as
     * a JSON column hands it back.
     *
     * @return array{array<string, mixed>, \stdClass}
     */
    private function create(Server $server): array
    {
        $handler = new class implements OperationHandlerInterface {
            public ?\stdClass $model = null;

            public function handle(JsonApiOperationInterface $operation): DataResponse
            {
                \assert($operation instanceof CreateResourceOperation);
                $resource = $operation->context()->server->serializerFor('nested-date-events');
                \assert($resource instanceof NestedDateEventResource);

                $model = $resource->hydrate($operation->body(), (object) ['id' => '1', 'window' => null, 'slots' => null]);
                \assert($model instanceof \stdClass);
                $this->model = $model;

                return DataResponse::fromResource($model, $resource)->withStatus(201);
            }
        };

        $psr17 = new Psr17Factory();
        $response = $server
            ->register(NestedDateEventResource::class)
            ->withPsr17($psr17, $psr17)
            ->withHandler($handler)
            ->handle(
                (new ServerRequest('POST', '/nested-date-events', ['Content-Type' => 'application/vnd.api+json']))
                    ->withBody($psr17->createStream(self::BODY))
                    ->withAttribute(Target::class, new Target('nested-date-events')),
            );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertNotNull($handler->model);

        return [self::attributes((string) $response->getBody()), self::throughJsonColumn($handler->model)];
    }

    /**
     * @return array<string, mixed>
     */
    private function read(Server $server, \stdClass $model): array
    {
        $handler = new class ($model) implements OperationHandlerInterface {
            public function __construct(private \stdClass $model) {}

            public function handle(JsonApiOperationInterface $operation): DataResponse
            {
                return DataResponse::fromResource($this->model, $operation->context()->server->serializerFor('nested-date-events'));
            }
        };

        $psr17 = new Psr17Factory();
        $response = $server
            ->register(NestedDateEventResource::class)
            ->withPsr17($psr17, $psr17)
            ->withHandler($handler)
            ->handle((new ServerRequest('GET', '/nested-date-events/1'))->withAttribute(Target::class, new Target('nested-date-events', '1')));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return self::attributes((string) $response->getBody());
    }

    /**
     * @return array<string, mixed>
     */
    private static function attributes(string $body): array
    {
        $document = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertIsArray($document['data'] ?? null);
        self::assertIsArray($document['data']['attributes'] ?? null);

        /** @var array<string, mixed> */
        return $document['data']['attributes'];
    }
}

final class NestedDateEventResource extends AbstractResource
{
    public static string $type = 'nested-date-events';

    public function fields(): array
    {
        return [
            Id::make(),
            Obj::make('window')->nullable()->fields(
                DateTime::make('startsAt'),
                DateTime::make('endsAt')->outputTimezone('America/New_York'),
                Date::make('day'),
                Time::make('opensAt'),
            ),
            ArrayList::make('slots')->nullable()->of(OneOf::make('slot')->variant('timed', DateTime::make('at'))),
        ];
    }
}
