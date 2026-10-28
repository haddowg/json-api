<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\FieldState;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToBuilder;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\RelationState;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationship;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationshipLinkage;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApi\Serializer\ToOneRelationshipLinkageInterface;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A `MorphTo` declared with `identifiedBy()`, rendered end to end through
 * {@see Server::handle()}: the linkage comes from the stored pair without reading the
 * target, which is read only to fill `included`.
 */
#[CoversClass(MorphTo::class)]
#[CoversClass(MorphToBuilder::class)]
#[CoversClass(AbstractRelationship::class)]
#[CoversClass(ToOneRelationship::class)]
final class MorphToIdentifiedByTest extends TestCase
{
    protected function setUp(): void
    {
        IdentifiedCampaignResource::$reads = 0;
    }

    #[Test]
    public function theLinkageRendersFromThePairWithoutReadingTheTarget(): void
    {
        $body = $this->get(self::campaign('agencies', 7), null);

        self::assertSame(['type' => 'agencies', 'id' => '7'], $body['data']['relationships']['target']['data']);
        self::assertSame(0, IdentifiedCampaignResource::$reads);
    }

    #[Test]
    public function aLoadStateReportingNotLoadedStillRendersThePair(): void
    {
        $notLoaded = new class implements RelationshipLoadStateInterface {
            public function isRelationshipLoaded(mixed $model, RelationInterface $relation): bool
            {
                return false;
            }
        };

        $body = $this->get(self::campaign('agencies', 4), null, null, $notLoaded);

        self::assertSame(['type' => 'agencies', 'id' => '4'], $body['data']['relationships']['target']['data']);
        self::assertSame(0, IdentifiedCampaignResource::$reads);
    }

    #[Test]
    public function aNullPairRendersAnEmptyRelation(): void
    {
        $campaign = self::campaign(null, null);

        $body = $this->get($campaign, 'target');

        self::assertArrayHasKey('data', $body['data']['relationships']['target']);
        self::assertNull($body['data']['relationships']['target']['data']);
        self::assertSame([], $body['included'] ?? []);
        self::assertSame(0, IdentifiedCampaignResource::$reads);
    }

    #[Test]
    public function anUndeclaredTypeIsALogicExceptionNamingTheResourceRelationAndType(): void
    {
        try {
            $this->get(self::campaign('publishers', 1), null);
            self::fail('Expected a LogicException.');
        } catch (\LogicException $e) {
            self::assertStringContainsString(IdentifiedCampaignResource::class, $e->getMessage());
            self::assertStringContainsString('"target"', $e->getMessage());
            self::assertStringContainsString('"publishers"', $e->getMessage());
        }
    }

    #[Test]
    public function aMalformedPairIsALogicException(): void
    {
        // Built directly: the builder's signature already refuses a closure typed like this one.
        $relation = new MorphTo(new FieldState('target', null), new RelationState(['agencies']), static fn(): array => ['agencies']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must return [type, id] or null');

        $relation->storedIdentifier(new \stdClass(), self::request());
    }

    #[Test]
    public function anIncludedTargetIsReadOffTheParentAndRendered(): void
    {
        $body = $this->get(self::campaign('agencies', 7), 'target');

        self::assertSame(['type' => 'agencies', 'id' => '7'], $body['data']['relationships']['target']['data']);
        self::assertSame([['type' => 'agencies', 'id' => '7', 'attributes' => ['name' => 'Agency 7']]], self::included($body));
        self::assertSame(1, IdentifiedCampaignResource::$reads);
    }

    #[Test]
    public function aHostSuppliedTargetFillsIncluded(): void
    {
        $seam = new SuppliedTargetLinkage(ToOneRelationshipLinkage::to(self::agency(7, 'Loaded in a batch')));

        $body = $this->get(self::campaign('agencies', 7), 'target', $seam);

        self::assertSame(['type' => 'agencies', 'id' => '7'], $body['data']['relationships']['target']['data']);
        self::assertSame([['type' => 'agencies', 'id' => '7', 'attributes' => ['name' => 'Loaded in a batch']]], self::included($body));
        self::assertSame(0, IdentifiedCampaignResource::$reads);
    }

    #[Test]
    public function aTargetOfAnotherTypeThanThePairIsLeftOutOfIncluded(): void
    {
        $campaign = self::campaign('agencies', 7);
        $campaign->target = self::advertiser(7);

        $body = $this->get($campaign, 'target');

        self::assertSame(['type' => 'agencies', 'id' => '7'], $body['data']['relationships']['target']['data']);
        self::assertSame([], self::included($body));
    }

    #[Test]
    public function theIdGoesThroughTheTargetTypesEncoder(): void
    {
        $body = $this->get(self::campaign('advertisers', 3), 'target');

        self::assertSame(['type' => 'advertisers', 'id' => 'adv-3'], $body['data']['relationships']['target']['data']);
        self::assertSame('adv-3', self::included($body)[0]['id']);
    }

    #[Test]
    public function theRelationshipEndpointRendersThePair(): void
    {
        $body = $this->handle(
            self::campaign('agencies', 7),
            null,
            '/campaigns/1/relationships/target',
            static fn(object $campaign, Server $server): IdentifierResponse => IdentifierResponse::forRelationship($campaign, $server->serializerFor('campaigns'), 'target'),
        );

        self::assertSame(['type' => 'agencies', 'id' => '7'], $body['data']);
        self::assertSame(0, IdentifiedCampaignResource::$reads);
    }

    #[Test]
    public function storedIdentifiersGroupsTheIdsByDeclaredTypeAndDedupes(): void
    {
        $relation = self::relation();

        $grouped = $relation->storedIdentifiers([
            self::campaign('agencies', 7),
            self::campaign('advertisers', 3),
            self::campaign(null, null),
            self::campaign('agencies', 7),
            self::campaign('agencies', '9'),
            self::campaign('advertisers', '3'),
        ], self::request());

        self::assertSame(['advertisers' => [3], 'agencies' => [7, '9']], $grouped);
        self::assertSame(['agencies', 7], $relation->storedIdentifier(self::campaign('agencies', 7), self::request()));
        self::assertNull($relation->storedIdentifier(self::campaign(null, null), self::request()));
        self::assertSame([], $relation->storedIdentifiers([], self::request()));
    }

    #[Test]
    public function storedIdentifiersRejectsAnUndeclaredType(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"publishers"');

        self::relation()->storedIdentifiers([self::campaign('agencies', 7), self::campaign('publishers', 1)], self::request());
    }

    #[Test]
    public function aRelationWithoutIdentifiedByStoresNoIdentifiers(): void
    {
        $plain = MorphTo::make('target', ['agencies'])->build();

        self::assertFalse($plain->storesIdentifiers());
        self::assertTrue(self::relation()->storesIdentifiers());

        $this->expectException(\LogicException::class);
        $plain->storedIdentifier(new \stdClass(), self::request());
    }

    #[Test]
    public function anIdentifiedRelationIsNeverLoadAware(): void
    {
        self::assertFalse(self::relation()->emitsDataOnlyWhenLoaded());
    }

    #[Test]
    public function identifierMetaCannotBeCombinedWithIdentifiedBy(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('identifierMeta()');

        MorphTo::make('target', ['agencies'])
            ->identifiedBy(static fn(): ?array => null)
            ->identifierMeta(static fn(): array => [])
            ->build();
    }

    private static function relation(): MorphTo
    {
        $relation = (new IdentifiedCampaignResource())->relationNamedIncludingHidden('target');
        self::assertInstanceOf(MorphTo::class, $relation);

        return $relation;
    }

    /**
     * @return array{data: array{relationships: array<string, array<string, mixed>>}, included?: list<array{type: string, id: string, attributes?: array<string, mixed>}>}
     */
    private function get(object $campaign, ?string $include, ?RelationshipLinkageInterface $seam = null, ?RelationshipLoadStateInterface $loadState = null): array
    {
        /** @var array{data: array{relationships: array<string, array<string, mixed>>}, included?: list<array{type: string, id: string, attributes?: array<string, mixed>}>} */
        return $this->handle(
            $campaign,
            $seam,
            '/campaigns/1',
            static fn(object $campaign, Server $server): DataResponse => DataResponse::fromResource($campaign, $server->serializerFor('campaigns')),
            $include,
            $loadState,
        );
    }

    /**
     * @param \Closure(object, Server): (DataResponse|IdentifierResponse) $respond
     *
     * @return array<string, mixed>
     */
    private function handle(
        object $campaign,
        ?RelationshipLinkageInterface $seam,
        string $uri,
        \Closure $respond,
        ?string $include = null,
        ?RelationshipLoadStateInterface $loadState = null,
    ): array {
        $psr17 = new Psr17Factory();
        $server = Server::make()
            ->register(IdentifiedCampaignResource::class)
            ->register(IdentifiedAgencyResource::class)
            ->register(IdentifiedAdvertiserResource::class)
            ->withRelationshipLinkage($seam)
            ->withRelationshipLoadState($loadState);

        $handler = new class ($campaign, $respond) implements OperationHandlerInterface {
            /**
             * @param \Closure(object, Server): (DataResponse|IdentifierResponse) $respond
             */
            public function __construct(private object $campaign, private \Closure $respond) {}

            public function handle(JsonApiOperationInterface $operation): DataResponse|IdentifierResponse
            {
                $server = $operation->context()->server;
                \assert($server instanceof Server);

                return ($this->respond)($this->campaign, $server);
            }
        };

        $target = \str_contains($uri, '/relationships/')
            ? new Target('campaigns', '1', \explode('/relationships/', $uri)[1], true)
            : new Target('campaigns', '1');

        $request = (new ServerRequest('GET', $include === null ? $uri : $uri . '?include=' . $include))->withAttribute(Target::class, $target);
        if ($include !== null) {
            $request = $request->withQueryParams(['include' => $include]);
        }

        $response = $server->withPsr17($psr17, $psr17)->withHandler($handler)->handle($request);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array<string, mixed> $body */
        $body = \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);

        return $body;
    }

    /**
     * @param array{included?: list<array{type: string, id: string, attributes?: array<string, mixed>}>} $body
     *
     * @return list<array{type: string, id: string, attributes?: array<string, mixed>}>
     */
    private static function included(array $body): array
    {
        return \array_map(
            static fn(array $resource): array => \array_intersect_key($resource, ['type' => true, 'id' => true, 'attributes' => true]),
            $body['included'] ?? [],
        );
    }

    private static function request(): JsonApiRequestInterface
    {
        return new JsonApiRequest(new ServerRequest('GET', '/campaigns'));
    }

    private static function campaign(?string $type, string|int|null $id): \stdClass
    {
        $target = match ($type) {
            'agencies' => self::agency((int) $id, 'Agency ' . $id),
            'advertisers' => self::advertiser((int) $id),
            default => null,
        };

        return (object) ['id' => '1', 'targetType' => $type, 'targetId' => $id, 'target' => $target];
    }

    private static function agency(int $id, string $name): \stdClass
    {
        return (object) ['id' => $id, 'name' => $name, 'kind' => 'agency'];
    }

    private static function advertiser(int $id): \stdClass
    {
        return (object) ['id' => $id, 'name' => 'Advertiser ' . $id, 'kind' => 'advertiser'];
    }
}

final class IdentifiedCampaignResource extends AbstractResource
{
    public static string $type = 'campaigns';

    public static int $reads = 0;

    public function fields(): array
    {
        return [
            Id::make(),
            MorphTo::make('target', ['advertisers', 'agencies'])
                ->identifiedBy(static fn(mixed $campaign): ?array => $campaign instanceof \stdClass && \is_string($campaign->targetType) && (\is_int($campaign->targetId) || \is_string($campaign->targetId))
                    ? [$campaign->targetType, $campaign->targetId]
                    : null)
                ->extractUsing(static function (mixed $campaign): mixed {
                    ++self::$reads;

                    return $campaign instanceof \stdClass ? $campaign->target : null;
                }),
        ];
    }
}

final class IdentifiedAgencyResource extends AbstractResource
{
    public static string $type = 'agencies';

    public function getType(mixed $object): string
    {
        return \is_object($object) && ($object->kind ?? null) === 'agency' ? 'agencies' : 'unknown';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}

final class IdentifiedAdvertiserResource extends AbstractResource
{
    public static string $type = 'advertisers';

    public function getType(mixed $object): string
    {
        return \is_object($object) && ($object->kind ?? null) === 'advertiser' ? 'advertisers' : 'unknown';
    }

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new PrefixedIdEncoder()),
        ];
    }
}

final class PrefixedIdEncoder implements IdEncoderInterface
{
    public function encode(mixed $storageKey): string
    {
        return 'adv-' . (\is_scalar($storageKey) ? (string) $storageKey : '');
    }

    public function decode(string $wireId): mixed
    {
        return \str_starts_with($wireId, 'adv-') ? \substr($wireId, 4) : null;
    }
}

final class SuppliedTargetLinkage implements ToOneRelationshipLinkageInterface
{
    public function __construct(private readonly ToOneRelationshipLinkage $target) {}

    public function linkageForRelationship(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): ?RelationshipLinkage
    {
        return null;
    }

    public function toOneLinkageForRelationship(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): ToOneRelationshipLinkage
    {
        return $this->target;
    }
}
