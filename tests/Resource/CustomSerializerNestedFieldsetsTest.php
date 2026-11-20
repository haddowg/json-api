<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Exception\FieldsetMemberUnrecognized;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\NestedFieldset;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\NestedFieldsets;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Schema\Profile\NestedFieldsetsProfile;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Serializer\DeclaresFieldNamesInterface;
use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Serializer\SerializesNestedFieldsetsInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Tests\Double\RecordingOperationHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * A type whose Resource declares `nestedFieldset()` but renders through a hand-written
 * serializer is narrowed by key as its Resource's fields say.
 */
#[CoversClass(NestedFieldsets::class)]
#[CoversClass(PolymorphicSerializer::class)]
#[Group('spec:sparse-fieldsets')]
#[Group('spec:extensions-and-profiles')]
final class CustomSerializerNestedFieldsetsTest extends TestCase
{
    protected function setUp(): void
    {
        SelectingVenueSerializer::$selections = [];
    }

    #[Test]
    public function anOverrideIsTrimmedToTheSelectedKeys(): void
    {
        $response = $this->render('places', 'address.postcode');

        self::assertSame(['address' => ['postcode' => 'AB1 2CD']], $this->attributes($response));
    }

    #[Test]
    public function aNarrowedOverrideAdvertisesTheProfile(): void
    {
        $response = $this->render('places', 'name,address.postcode');

        self::assertSame('application/vnd.api+json; profile="' . NestedFieldsetsProfile::URI . '"', $response->getHeaderLine('Content-Type'));
        self::assertSame(['version' => '1.1', 'profile' => [NestedFieldsetsProfile::URI]], $this->body($response)['jsonapi']);
    }

    #[Test]
    public function anOverrideIsTrimmedThroughNestedObjectsAndLists(): void
    {
        $response = $this->render('places', 'address.geo.lat,visits.*.day');

        self::assertSame(
            ['address' => ['geo' => ['lat' => 51.5]], 'visits' => [['day' => 'mon'], ['day' => 'tue']]],
            $this->attributes($response),
        );
    }

    #[Test]
    public function anAttributeListedWholeRendersWhole(): void
    {
        $response = $this->render('places', 'address,address.postcode');

        self::assertSame(['address' => PlaceResource::model()['address']], $this->attributes($response));
        self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function anOverridesListSelectedThroughAWildcardAloneRendersWholeWithoutTheProfile(): void
    {
        $whole = $this->render('places', 'visits');
        $response = $this->render('places', 'visits.*');

        self::assertSame(['visits' => PlaceResource::model()['visits']], $this->attributes($response));
        self::assertSame($this->attributes($whole), $this->attributes($response));
        self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function aSerializerSelectingKeysReceivesTheSelectionAndIsStillTrimmed(): void
    {
        $response = $this->render('venues', 'address.postcode,address.geo.lat');

        self::assertSame([['postcode', 'geo.lat']], SelectingVenueSerializer::$selections);
        self::assertSame(['address' => ['postcode' => 'AB1 2CD', 'geo' => ['lat' => 51.5]]], $this->attributes($response));
    }

    #[Test]
    public function aSerializerSelectingKeysIsNotAskedForAnAttributeItLeavesOut(): void
    {
        $response = $this->render('venues', 'name,secret.code');

        self::assertSame([], SelectingVenueSerializer::$selections);
        self::assertSame(['name' => 'Hall'], $this->attributes($response));
    }

    #[Test]
    public function aKeyTheResourceRejectsSelectsNothingWithoutStrictValidation(): void
    {
        $response = $this->render('places', 'name,address.unknown,name.first');

        self::assertSame(['name' => 'Hall'], $this->attributes($response));
        self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function aKeyTheResourceAcceptsPassesStrictValidationOfAnOverrideDeclaringItsFieldNames(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $operation = new NestedFieldsetOperation($this->request('places', 'name,address.postcode,visits.*.day'), 'places');

        $this->server()->withHandler($handler)->dispatch($operation);

        self::assertSame($operation, $handler->received);
    }

    #[Test]
    public function aKeyTheResourceRejectsIsThe400ForAnOverrideDeclaringItsFieldNames(): void
    {
        try {
            $this->server()->withHandler(new RecordingOperationHandler(MetaResponse::fromMeta([])))
                ->dispatch(new NestedFieldsetOperation($this->request('places', 'address.unknown,name.first'), 'places'));
            self::fail('Expected the members to be rejected.');
        } catch (FieldsetMemberUnrecognized $e) {
            self::assertSame(['address.unknown', 'name.first'], $e->unrecognizedMembers);
        }
    }

    #[Test]
    public function aBarePairHasNoFieldsToSelectKeysFrom(): void
    {
        $response = $this->render('kiosks', 'address.postcode');

        self::assertArrayNotHasKey('attributes', $this->data($response));
        self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function aPolymorphicSerializerForwardsTheSelectionToItsMember(): void
    {
        $server = $this->server();
        $polymorphic = new PolymorphicSerializer(static fn(mixed $object): SerializerInterface => $server->serializerFor('venues'));
        $fieldset = new NestedFieldset(['postcode']);

        $value = $polymorphic->getNestedFieldsetAttribute(PlaceResource::model(), $this->request('venues', 'address.postcode'), 'address', $fieldset);

        self::assertSame([['postcode']], SelectingVenueSerializer::$selections);
        self::assertSame(PlaceResource::model()['address'], $value);
    }

    #[Test]
    public function aPolymorphicSerializerCallsTheAttributeOfAMemberThatSelectsNoKeys(): void
    {
        $server = $this->server();
        $polymorphic = new PolymorphicSerializer(static fn(mixed $object): SerializerInterface => $server->serializerFor('places'));
        $request = $this->request('places', 'address.postcode');

        self::assertSame(PlaceResource::model()['address'], $polymorphic->getNestedFieldsetAttribute(PlaceResource::model(), $request, 'address', new NestedFieldset(['postcode'])));
        self::assertNull($polymorphic->getNestedFieldsetAttribute(PlaceResource::model(), $request, 'missing', new NestedFieldset(['postcode'])));
    }

    #[Test]
    public function attributeFieldsetReadsTheResourceTheTypeWasRegisteredWith(): void
    {
        $server = $this->server();
        $serializer = $server->serializerFor('places');
        $request = $this->request('places', 'address.postcode,address.geo.lat');

        self::assertSame(['postcode', 'geo.lat'], NestedFieldsets::attributeFieldset($serializer, 'places', 'address', $request, $server)?->paths());
        self::assertNull(NestedFieldsets::attributeFieldset($serializer, 'places', 'address', $request));
        self::assertNull(NestedFieldsets::attributeFieldset($serializer, 'places', 'name', $request, $server));
    }

    private function server(): Server
    {
        $psr17 = new Psr17Factory();

        return Server::make()
            ->withPsr17($psr17, $psr17)
            ->register(PlaceResource::class, serializer: PlaceSerializer::class)
            ->register(VenueResource::class, serializer: SelectingVenueSerializer::class)
            ->registerSerializerHydrator('kiosks', serializer: KioskSerializer::class)
            ->withProfile(new NestedFieldsetsProfile());
    }

    private function request(string $type, string $fields): JsonApiRequestInterface
    {
        return new JsonApiRequest((new ServerRequest('GET', '/' . $type . '/1'))->withQueryParams(['fields' => [$type => $fields]]));
    }

    private function render(string $type, string $fields): ResponseInterface
    {
        $server = $this->server();

        return DataResponse::fromResource(PlaceResource::model(), $server->serializerFor($type))
            ->toPsrResponse($server, $this->request($type, $fields));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ResponseInterface $response): array
    {
        $body = \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ResponseInterface $response): array
    {
        $data = $this->body($response)['data'];
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ResponseInterface $response): array
    {
        $attributes = $this->data($response)['attributes'] ?? [];
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }
}

final class PlaceResource extends AbstractResource
{
    public static string $type = 'places';

    /**
     * @return array<string, mixed>
     */
    public static function model(): array
    {
        return [
            'id' => '1',
            'name' => 'Hall',
            'address' => ['street' => '1 High Street', 'postcode' => 'AB1 2CD', 'geo' => ['lat' => 51.5, 'lng' => -0.1]],
            'visits' => [['day' => 'mon', 'count' => 3], ['day' => 'tue', 'count' => 5]],
            'secret' => ['code' => 'x'],
        ];
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Obj::make('address')->fields(
                Str::make('street'),
                Str::make('postcode'),
                Obj::make('geo')->fields(Decimal::make('lat'), Decimal::make('lng'))->nestedFieldset(pattern: 'lat|lng'),
            )->nestedFieldset(pattern: 'street|postcode|geo'),
            ArrayList::make('visits')->of(Obj::make('visit')->fields(Str::make('day'), Integer::make('count'))->nestedFieldset(pattern: 'day|count')),
            Obj::make('secret')->fields(Str::make('code'))->nestedFieldset(pattern: 'code'),
        ];
    }
}

final class VenueResource extends AbstractResource
{
    public static string $type = 'venues';

    public function fields(): array
    {
        return (new PlaceResource())->fields();
    }
}

/**
 * Renders every attribute whole, as a serializer written before nested fieldsets would.
 */
final class PlaceSerializer extends AbstractSerializer implements DeclaresFieldNamesInterface
{
    public function getType(mixed $object): string
    {
        return 'places';
    }

    public function getId(mixed $object): string
    {
        return '1';
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        \assert(\is_array($object));

        return [
            'name' => static fn(): mixed => $object['name'],
            'address' => static fn(): mixed => $object['address'],
            'visits' => static fn(): mixed => $object['visits'],
        ];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function declaredFieldNames(): array
    {
        return ['id', 'name', 'address', 'visits', 'secret'];
    }
}

/**
 * Receives the selection, and still returns the whole address so the trim shows.
 */
final class SelectingVenueSerializer implements SerializerInterface, SerializesNestedFieldsetsInterface
{
    /**
     * @var list<list<string>>
     */
    public static array $selections = [];

    public function getType(mixed $object): string
    {
        return 'venues';
    }

    public function getId(mixed $object): string
    {
        return '1';
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        \assert(\is_array($object));

        return [
            'name' => static fn(): mixed => $object['name'],
            'address' => static fn(): mixed => $object['address'],
        ];
    }

    public function getNestedFieldsetAttribute(mixed $object, JsonApiRequestInterface $request, string $name, NestedFieldset $fieldset): mixed
    {
        \assert(\is_array($object));
        self::$selections[] = $fieldset->paths();

        return $object[$name];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }
}

final class KioskSerializer extends AbstractSerializer
{
    public function getType(mixed $object): string
    {
        return 'kiosks';
    }

    public function getId(mixed $object): string
    {
        return '1';
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        \assert(\is_array($object));

        return ['address' => static fn(): mixed => $object['address']];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }
}

final readonly class NestedFieldsetOperation implements \haddowg\JsonApi\Operation\JsonApiOperationInterface
{
    public function __construct(private JsonApiRequestInterface $request, private string $type) {}

    public function target(): \haddowg\JsonApi\Operation\Target
    {
        return new \haddowg\JsonApi\Operation\Target($this->type);
    }

    public function queryParameters(): \haddowg\JsonApi\Operation\QueryParameters
    {
        return \haddowg\JsonApi\Operation\QueryParameters::fromRequest($this->request);
    }

    public function context(): \haddowg\JsonApi\Operation\OperationContext
    {
        return new \haddowg\JsonApi\Operation\OperationContext(new \haddowg\JsonApi\Tests\Double\StubServer(), $this->request);
    }
}
