<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Atomic\AtomicOperationsParser;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\PivotEdge;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\MetaContext;
use haddowg\JsonApi\Resource\RenderedMeta;
use haddowg\JsonApi\Resource\ResourceMetaMismatch;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RenderedMeta::class)]
#[CoversClass(AbstractResource::class)]
#[CoversClass(BelongsToMany::class)]
#[CoversClass(AtomicOperationsParser::class)]
#[CoversClass(PolymorphicSerializer::class)]
final class TypedMetaRuntimeTest extends TestCase
{
    #[Test]
    public function pivotFieldsKeepTheirEntryApart(): void
    {
        $relation = BelongsToMany::make('rooms', 'rooms')->fields(
            Integer::make('nights'),
            AdditionalProperties::make()->of(Str::make('*'))->extractUsing(static fn(PivotEdge $edge): array => ['view' => 'sea', 'nights' => 'shadowed']),
        )->build();

        self::assertSame(['nights'], \array_map(static fn($field): string => $field->name(), $relation->pivotFields()));
        self::assertNotNull($relation->pivotAdditionalProperties());
        self::assertTrue($relation->pivotMetaShape()->isOpen());
        self::assertSame(['view' => 'sea'], $relation->hookedPivotValues(['id' => 'b1'], ['id' => 'r1'], ['nights' => 2], self::request()));
    }

    #[Test]
    public function aPivotDeclaresAtMostOneEntry(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('more than one additionalProperties entry among its pivot fields');

        BelongsToMany::make('rooms', 'rooms')->fields(AdditionalProperties::make(), AdditionalProperties::make());
    }

    #[Test]
    public function aPivotWithoutAnEntryIsClosed(): void
    {
        $relation = BelongsToMany::make('rooms', 'rooms')->fields(Integer::make('nights'))->build();

        self::assertNull($relation->pivotAdditionalProperties());
        self::assertFalse($relation->pivotMetaShape()->isOpen());
        self::assertSame(['/data/0/meta/pivot/view'], $relation->pivotMetaShape()->undeclared(['nights' => 1, 'view' => 'sea'], '/data/0/meta/pivot'));
    }

    #[Test]
    public function identifierMetaKeepsItsEntryApartAndTakesNoHook(): void
    {
        $relation = BelongsTo::make('guest', 'people')
            ->identifierMeta(static fn(mixed $parent, mixed $related): array => ['role' => 'lead'], [Str::make('role'), AdditionalProperties::make()->of(Str::make('*'))])
            ->build();

        self::assertSame(['role'], \array_map(static fn($field): string => $field->name(), $relation->identifierMetaFields()));
        self::assertNotNull($relation->identifierMetaAdditionalProperties());
        self::assertTrue($relation->identifierMetaShape()->isOpen());
    }

    #[Test]
    public function anIdentifierMetaEntryWithAHookIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the identifierMeta() resolver supplies every value');

        BelongsTo::make('guest', 'people')->identifierMeta(static fn(): array => [], [AdditionalProperties::make()->extractUsing(static fn(): array => [])]);
    }

    #[Test]
    public function identifierMetaDeclaresAtMostOneEntry(): void
    {
        $this->expectException(\LogicException::class);

        BelongsTo::make('guest', 'people')->identifierMeta(static fn(): array => [], [AdditionalProperties::make(), AdditionalProperties::make()]);
    }

    #[Test]
    public function theRenderedCheckFlagsUndeclaredResourceMeta(): void
    {
        $bookings = self::bookings();
        $document = [
            'data' => ['type' => 'bookings', 'id' => '1', 'meta' => ['nights' => 3, 'extra' => true]],
            'included' => [['type' => 'bookings', 'id' => '2', 'meta' => ['nights' => 'three']]],
        ];

        self::assertSame([
            'the meta of "bookings" "1": the member "extra" is not declared',
            'the meta of "bookings" "2": the member "nights" is string, not integer or null',
        ], RenderedMeta::violations($document, static fn(string $type): ?object => $type === 'bookings' ? $bookings : null));
    }

    #[Test]
    public function theRenderedCheckLeavesUndeclaringTypesOpen(): void
    {
        $plain = new class extends AbstractResource {
            public static string $type = 'rooms';

            public function fields(): array
            {
                return [Id::make()];
            }
        };

        $document = ['data' => [['type' => 'rooms', 'id' => '1', 'meta' => ['anything' => 1]], ['type' => 'unknown', 'id' => '2', 'meta' => ['x' => 1]], 'not an object']];

        self::assertSame([], RenderedMeta::violations($document, static fn(string $type): ?object => $type === 'rooms' ? $plain : null));
        RenderedMeta::assertConforms($document, static fn(string $type): ?object => $type === 'rooms' ? $plain : null);
    }

    #[Test]
    public function theRenderedCheckFlagsUndeclaredPivotAndIdentifierMeta(): void
    {
        $bookings = self::bookings();
        $document = ['data' => ['type' => 'bookings', 'id' => '1', 'meta' => ['nights' => 1], 'relationships' => [
            'rooms' => ['data' => [['type' => 'rooms', 'id' => '1', 'meta' => ['pivot' => ['nights' => 1, 'view' => 'sea'], 'role' => 'x', 'stray' => 1]]]],
            'guest' => ['data' => ['type' => 'people', 'id' => '1', 'meta' => ['free' => 'form']]],
            'agent' => ['data' => ['type' => 'people', 'id' => '2', 'meta' => ['since' => 2020, 'other' => 1]]],
        ]]];

        self::assertSame([
            'the linkage of "bookings" "1" relationship "rooms": pivot the member "view" is not declared',
            'the linkage of "bookings" "1" relationship "agent": the member "other" is not declared',
        ], RenderedMeta::violations($document, static fn(string $type): ?object => $type === 'bookings' ? $bookings : null));

        $this->expectException(ResourceMetaMismatch::class);
        RenderedMeta::assertConforms($document, static fn(string $type): ?object => $type === 'bookings' ? $bookings : null);
    }

    #[Test]
    public function hydrationHandsDataMetaToItsFillHooksOnCreateAndUpdate(): void
    {
        $resource = self::bookings();

        $created = $resource->hydrate(self::write('POST', ['type' => 'bookings', 'attributes' => ['title' => 'Stay'], 'meta' => ['source' => 'web', 'reason' => 'kept off']]), []);
        self::assertSame(['title' => 'Stay', 'source' => 'web'], $created);

        $updated = $resource->hydrate(self::write('PATCH', ['type' => 'bookings', 'id' => '1', 'meta' => ['source' => 'app']]), ['title' => 'Stay']);
        self::assertSame(['title' => 'Stay', 'source' => 'app'], $updated);

        $untouched = $resource->hydrate(self::write('PATCH', ['type' => 'bookings', 'id' => '1', 'meta' => 'not an object']), ['title' => 'Stay']);
        self::assertSame(['title' => 'Stay'], $untouched);
    }

    #[Test]
    public function theAtomicParserCarriesEachOperationsMeta(): void
    {
        $descriptors = (new AtomicOperationsParser())->parse(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'bookings'], 'meta' => ['idempotencyKey' => 'k1']],
            ['op' => 'remove', 'ref' => ['type' => 'bookings', 'id' => '1']],
        ]]);

        self::assertSame(['idempotencyKey' => 'k1'], $descriptors[0]->meta);
        self::assertNull($descriptors[1]->meta);
    }

    #[Test]
    public function aRenderedResourceObjectCarriesItsDeclaredMetaDirectlyAndThroughAPolymorphicSerializer(): void
    {
        $bookings = self::bookings();
        $request = new ServerRequest('GET', '/bookings/1');
        $booking = ['id' => '1', 'title' => 'Stay', 'nights' => 3];

        $direct = self::decode((string) DataResponse::fromResource($booking, $bookings)->toPsrResponse(new StubServer(), $request)->getBody());
        self::assertSame(['nights' => 3], self::member(self::member($direct, 'data'), 'meta'));

        $polymorphic = new PolymorphicSerializer(static fn(): AbstractResource => $bookings, [$bookings]);
        $through = self::decode((string) DataResponse::fromResource($booking, $polymorphic)->toPsrResponse(new StubServer(), $request)->getBody());
        self::assertSame(['nights' => 3], self::member(self::member($through, 'data'), 'meta'));
    }

    private static function bookings(): AbstractResource
    {
        return new class extends AbstractResource {
            public static string $type = 'bookings';

            public function fields(): array
            {
                return [
                    Id::make(),
                    Str::make('title'),
                    BelongsToMany::make('rooms', 'rooms')->fields(Integer::make('nights')),
                    BelongsTo::make('guest', 'people')->identifierMeta(static fn(): array => ['free' => 'form']),
                    BelongsTo::make('agent', 'people')->identifierMeta(static fn(): array => ['since' => 2020], [Integer::make('since')]),
                ];
            }

            public function resourceMeta(MetaContext $context): iterable
            {
                return [
                    Integer::make('nights')->readOnly()->extractUsing(static fn(array $booking): mixed => $booking['nights'] ?? null),
                    Str::make('source')->writeOnly()->fillUsing(static function (array $booking, mixed $value): array {
                        $booking['source'] = $value;

                        return $booking;
                    }),
                    Str::make('reason')->writeOnly(),
                ];
            }
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function write(string $method, array $data): JsonApiRequestInterface
    {
        return new JsonApiRequest((new ServerRequest($method, '/bookings'))->withParsedBody(['data' => $data]));
    }

    private static function request(): JsonApiRequestInterface
    {
        return new JsonApiRequest(new ServerRequest('GET', '/bookings'));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(string $json): array
    {
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function member(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        self::assertIsArray($value, $key);

        return $value;
    }
}
