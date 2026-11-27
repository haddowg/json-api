<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Exception\RelationshipCountNotAllowed;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ForeignKey;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\Internal\StoredPair;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Internal\RegisteredResource;
use haddowg\JsonApi\Resource\ProvidesResourcesInterface;
use haddowg\JsonApi\Resource\RendersRelationsTrait;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Schema\Document\AbstractResourceDocument;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Serializer\CountableControlsInterface;
use haddowg\JsonApi\Serializer\RelationshipCountInterface;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApi\Serializer\RelationshipPaginationInterface;
use haddowg\JsonApi\Serializer\ResourceLinkContributorInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An adapter wraps a target type's serializer in a decorator, and the identified
 * relations still render its stored ids through the id encoder of the Resource the
 * type was registered with.
 */
#[CoversClass(StoredPair::class)]
#[CoversClass(RegisteredResource::class)]
#[CoversClass(MorphTo::class)]
#[CoversClass(MorphToMany::class)]
#[CoversClass(ForeignKey::class)]
#[CoversClass(IdentifierResponse::class)]
#[CoversClass(AbstractResourceDocument::class)]
final class DecoratedSerializerStoredPairTest extends TestCase
{
    private const string STORED_UUID = 'A1B2C3D4-E5F6-4A7B-8C9D-0E1F2A3B4C5D';
    private const string UUID = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function reachableResources(): iterable
    {
        yield 'a decorator forwarding its resolver' => [true, false];
        yield 'a resolver providing resources' => [false, true];
    }

    #[Test]
    #[DataProvider('reachableResources')]
    public function anIdentifiedMorphToEncodesTheStoredIdOnLinkageAndIncluded(bool $forwardsResolver, bool $providesResources): void
    {
        $body = $this->get(self::fleet(['ships', 2], []), 'flagship', $forwardsResolver, $providesResources);

        self::assertSame(['type' => 'ships', 'id' => 'ship-2'], $body['data']['relationships']['flagship']['data']);
        self::assertSame([['type' => 'ships', 'id' => 'ship-2']], self::included($body));
    }

    #[Test]
    #[DataProvider('reachableResources')]
    public function anIdentifiedMorphToManyEncodesTheStoredIdsOnLinkageAndIncluded(bool $forwardsResolver, bool $providesResources): void
    {
        $body = $this->get(self::fleet(null, [['ships', 2], ['docks', self::STORED_UUID]]), 'vessels', $forwardsResolver, $providesResources);

        self::assertSame(
            [['type' => 'ships', 'id' => 'ship-2'], ['type' => 'docks', 'id' => self::UUID]],
            $body['data']['relationships']['vessels']['data'],
        );
        self::assertSame([['type' => 'ships', 'id' => 'ship-2'], ['type' => 'docks', 'id' => self::UUID]], self::included($body));
    }

    #[Test]
    #[DataProvider('reachableResources')]
    public function anUpperCaseUuidWithNoEncoderRendersInLowerCase(bool $forwardsResolver, bool $providesResources): void
    {
        $body = $this->get(self::fleet(['docks', self::STORED_UUID], []), 'flagship', $forwardsResolver, $providesResources);

        self::assertSame(['type' => 'docks', 'id' => self::UUID], $body['data']['relationships']['flagship']['data']);
        self::assertSame([['type' => 'docks', 'id' => self::UUID]], self::included($body));
    }

    #[Test]
    public function withNoResourceToReachAnUpperCaseUuidStillRendersInLowerCase(): void
    {
        $body = $this->get(self::fleet(null, [['docks', self::STORED_UUID]]), null, false, false);

        self::assertSame([['type' => 'docks', 'id' => self::UUID]], $body['data']['relationships']['vessels']['data']);
    }

    #[Test]
    public function theParentKeyOfADecoratedSerializerIsDecoded(): void
    {
        $server = self::server();
        $ship = (object) ['id' => 2, 'kind' => 'ship'];

        self::assertSame('2', ForeignKey::parentKey(new DecoratedTargetSerializer($server->serializerFor('ships'), true), $ship));
    }

    #[Test]
    public function aWriteOnlyRelationOfADecoratedParentAnswersAMutationWithNoContent(): void
    {
        $server = self::server();
        $parent = new DecoratedParentSerializer($server->serializerFor('fleets'), new DecoratingResolver($server, false));
        $request = new JsonApiRequest(new ServerRequest('PATCH', '/fleets/1/relationships/crew'));

        $response = IdentifierResponse::forRelationship(self::fleet(null, []), $parent, 'crew')->toPsrResponse($server, $request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function aWriteOnlyRelationOfADecoratedPrimaryIsNotCountable(): void
    {
        $server = self::server();
        $parent = new DecoratedParentSerializer($server->serializerFor('fleets'), new DecoratingResolver($server, false));
        $request = new JsonApiRequest(
            (new ServerRequest('GET', '/fleets/1'))
                ->withQueryParams(['withCount' => 'crew'])
                ->withHeader('Accept', 'application/vnd.api+json;profile="' . CountableProfile::URI . '"'),
        );

        $this->expectException(RelationshipCountNotAllowed::class);

        DataResponse::fromResource(self::fleet(null, []), $parent)->toPsrResponse($server, $request);
    }

    private static function server(): Server
    {
        $psr17 = new Psr17Factory();

        return Server::make()
            ->withPsr17($psr17, $psr17)
            ->register(DecoratedFleetResource::class)
            ->register(DecoratedShipResource::class)
            ->register(DecoratedDockResource::class);
    }

    /**
     * @return array{data: array{relationships: array<string, array<string, mixed>>}, included?: list<array{type: string, id: string}>}
     */
    private function get(object $fleet, ?string $include, bool $forwardsResolver, bool $providesResources): array
    {
        $server = self::server();

        $handler = new class ($fleet, $forwardsResolver, $providesResources) implements OperationHandlerInterface {
            public function __construct(private object $fleet, private bool $forwardsResolver, private bool $providesResources) {}

            public function handle(JsonApiOperationInterface $operation): DataResponse
            {
                $server = $operation->context()->server;
                \assert($server instanceof Server);
                $resolver = new DecoratingResolver($server, $this->providesResources, $this->forwardsResolver);

                return DataResponse::fromResource($this->fleet, new DecoratedParentSerializer($server->serializerFor('fleets'), $resolver));
            }
        };

        $request = (new ServerRequest('GET', '/fleets/1'))->withAttribute(Target::class, new Target('fleets', '1'));
        if ($include !== null) {
            $request = $request->withQueryParams(['include' => $include]);
        }

        $response = $server->withHandler($handler)->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array{data: array{relationships: array<string, array<string, mixed>>}, included?: list<array{type: string, id: string}>} */
        return \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array{included?: list<array{type: string, id: string}>} $body
     *
     * @return list<array{type: string, id: string}>
     */
    private static function included(array $body): array
    {
        return \array_map(
            static fn(array $resource): array => ['type' => $resource['type'], 'id' => $resource['id']],
            $body['included'] ?? [],
        );
    }

    /**
     * @param array{0: string, 1: string|int}|null $flagship
     * @param list<array{0: string, 1: string|int}> $vessels
     */
    private static function fleet(?array $flagship, array $vessels): \stdClass
    {
        $load = static fn(array $pair): \stdClass => $pair[0] === 'ships'
            ? (object) ['id' => $pair[1], 'kind' => 'ship', 'name' => 'Ship']
            : (object) ['id' => \strtolower((string) $pair[1]), 'kind' => 'dock', 'name' => 'Dock'];

        return (object) [
            'id' => '1',
            'flagshipPair' => $flagship,
            'flagship' => $flagship === null ? null : $load($flagship),
            'vesselPairs' => $vessels,
            'vessels' => \array_map($load, $vessels),
        ];
    }
}

final class DecoratedFleetResource extends AbstractResource
{
    public static string $type = 'fleets';

    public function fields(): array
    {
        return [
            Id::make(),
            MorphTo::make('flagship', ['ships', 'docks'])
                ->identifiedBy(static fn(mixed $fleet): ?array => $fleet instanceof \stdClass && StoredPair::isPair($fleet->flagshipPair) ? $fleet->flagshipPair : null)
                ->extractUsing(static fn(mixed $fleet): mixed => $fleet instanceof \stdClass ? $fleet->flagship : null),
            MorphToMany::make('vessels', ['ships', 'docks'])
                ->identifiedBy(static fn(mixed $fleet): array => $fleet instanceof \stdClass && \is_array($fleet->vesselPairs) ? $fleet->vesselPairs : [])
                ->extractUsing(static fn(mixed $fleet): mixed => $fleet instanceof \stdClass ? $fleet->vessels : []),
            HasMany::make('crew', 'docks')->countable()->writeOnly(static fn(): bool => true),
        ];
    }
}

final class DecoratedShipResource extends AbstractResource
{
    public static string $type = 'ships';

    public function getType(mixed $object): string
    {
        return \is_object($object) && ($object->kind ?? null) === 'ship' ? 'ships' : 'unknown';
    }

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new ShipIdEncoder()),
            Str::make('name'),
        ];
    }
}

final class DecoratedDockResource extends AbstractResource
{
    public static string $type = 'docks';

    public function getType(mixed $object): string
    {
        return \is_object($object) && ($object->kind ?? null) === 'dock' ? 'docks' : 'unknown';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}

final class ShipIdEncoder implements IdEncoderInterface
{
    public function encode(mixed $storageKey): string
    {
        return 'ship-' . (\is_scalar($storageKey) ? (string) $storageKey : '');
    }

    public function decode(string $wireId): mixed
    {
        return \str_starts_with($wireId, 'ship-') ? \substr($wireId, 5) : null;
    }
}

/**
 * Wraps a target type's serializer, delegating everything, as an adapter's decorator
 * rendering extra meta would. It reports its inner resolver only when told to.
 */
final class DecoratedTargetSerializer implements SerializerInterface, SerializerResolverAwareInterface
{
    public function __construct(private readonly SerializerInterface $inner, private readonly bool $forwardsResolver) {}

    public function getType(mixed $object): string
    {
        return $this->inner->getType($object);
    }

    public function getId(mixed $object): string
    {
        return $this->inner->getId($object);
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner->getMeta($object, $request);
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return $this->inner->getLinks($object, $request);
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner->getAttributes($object, $request);
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return $this->inner->getDefaultIncludedRelationships($object);
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner->getRelationships($object, $request);
    }

    public function setSerializerResolver(SerializerResolverInterface $resolver): void {}

    public function serializerResolver(): ?SerializerResolverInterface
    {
        return $this->forwardsResolver && $this->inner instanceof SerializerResolverAwareInterface ? $this->inner->serializerResolver() : null;
    }
}

/**
 * Hands out every type's serializer wrapped in a {@see DecoratedTargetSerializer}, and
 * passes the server's Resources through only when told to.
 */
final class DecoratingResolver implements SerializerResolverInterface, ProvidesResourcesInterface
{
    public function __construct(
        private readonly Server $inner,
        private readonly bool $providesResources,
        private readonly bool $forwardsResolver = false,
    ) {}

    public function serializerFor(string $type): SerializerInterface
    {
        return new DecoratedTargetSerializer($this->inner->serializerFor($type), $this->forwardsResolver);
    }

    public function hasSerializerFor(string $type): bool
    {
        return $this->inner->hasSerializerFor($type);
    }

    public function hasResourceFor(string $type): bool
    {
        return $this->providesResources && $this->inner->hasResourceFor($type);
    }

    public function resourceFor(string $type): AbstractResource
    {
        return $this->inner->resourceFor($type);
    }

    public function relationshipLoadState(): ?RelationshipLoadStateInterface
    {
        return $this->inner->relationshipLoadState();
    }

    public function relationshipCount(): ?RelationshipCountInterface
    {
        return $this->inner->relationshipCount();
    }

    public function relationshipPagination(): ?RelationshipPaginationInterface
    {
        return $this->inner->relationshipPagination();
    }

    public function relationshipLinkage(): ?RelationshipLinkageInterface
    {
        return $this->inner->relationshipLinkage();
    }

    public function resourceLinkContributor(): ?ResourceLinkContributorInterface
    {
        return $this->inner->resourceLinkContributor();
    }
}

/**
 * Wraps the parent's Resource and rebuilds its relations against another resolver,
 * forwarding its countable relations.
 */
final class DecoratedParentSerializer extends AbstractSerializer implements CountableControlsInterface
{
    use RendersRelationsTrait;

    public function __construct(private readonly AbstractResource|SerializerInterface $inner, private readonly SerializerResolverInterface $resolver) {}

    public function getType(mixed $object): string
    {
        return $this->inner->getType($object);
    }

    public function getId(mixed $object): string
    {
        return $this->inner->getId($object);
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return $this->inner->getLinks($object, $request);
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner->getAttributes($object, $request);
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return $this->inner->getDefaultIncludedRelationships($object);
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        if (!$this->inner instanceof AbstractResource) {
            return $this->inner->getRelationships($object, $request);
        }

        $relationships = $this->inner->getRelationships($object, $request);
        foreach (\array_keys($relationships) as $name) {
            $relation = $this->inner->relationNamedIncludingHidden($name);
            if ($relation !== null) {
                $relationships[$name] = self::relationshipCallable($relation, $this->resolver);
            }
        }

        return $relationships;
    }

    public function getCountableRelationships(mixed $object): array
    {
        return $this->inner instanceof CountableControlsInterface ? $this->inner->getCountableRelationships($object) : [];
    }
}
