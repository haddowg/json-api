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
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\MorphToManyBuilder;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\RelationState;
use haddowg\JsonApi\Resource\Field\StoresIdentifiersInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
use haddowg\JsonApi\Schema\Relationship\ToManyRelationship;
use haddowg\JsonApi\Serializer\RelationshipCountInterface;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A `MorphToMany` declared with `identifiedBy()`, rendered end to end through
 * {@see Server::handle()}: the linkage comes from the stored pairs without reading the
 * members, which are read only to fill `included`.
 */
#[CoversClass(MorphToMany::class)]
#[CoversClass(MorphToManyBuilder::class)]
#[CoversClass(AbstractRelationship::class)]
#[CoversClass(ToManyRelationship::class)]
#[CoversClass(IdentifiedMember::class)]
final class MorphToManyIdentifiedByTest extends TestCase
{
    protected function setUp(): void
    {
        IdentifiedUserResource::$reads = 0;
    }

    #[Test]
    public function theLinkageRendersThePairsInOrderWithoutReadingAMember(): void
    {
        $body = $this->get(self::user(['agencies', 7], ['advertisers', 3], ['agencies', 2]), null);

        self::assertSame([
            ['type' => 'agencies', 'id' => '7'],
            ['type' => 'advertisers', 'id' => 'adv-3'],
            ['type' => 'agencies', 'id' => '2'],
        ], $body['data']['relationships']['companies']['data']);
        self::assertSame(0, IdentifiedUserResource::$reads);
    }

    #[Test]
    public function aRepeatedPairRendersOnce(): void
    {
        $body = $this->get(self::user(['agencies', 7], ['agencies', '7'], ['advertisers', 7]), null);

        self::assertSame([
            ['type' => 'agencies', 'id' => '7'],
            ['type' => 'advertisers', 'id' => 'adv-7'],
        ], $body['data']['relationships']['companies']['data']);
    }

    #[Test]
    public function aLoadStateReportingNotLoadedStillRendersThePairs(): void
    {
        $notLoaded = new class implements RelationshipLoadStateInterface {
            public function isRelationshipLoaded(mixed $model, RelationInterface $relation): bool
            {
                return false;
            }
        };

        $body = $this->get(self::user(['agencies', 4]), null, null, $notLoaded);

        self::assertSame([['type' => 'agencies', 'id' => '4']], $body['data']['relationships']['companies']['data']);
        self::assertSame(0, IdentifiedUserResource::$reads);
    }

    #[Test]
    public function noPairsRenderAnEmptyList(): void
    {
        $body = $this->get(self::user(), 'companies');

        self::assertSame([], $body['data']['relationships']['companies']['data']);
        self::assertSame([], self::included($body));
    }

    #[Test]
    public function anUndeclaredTypeIsALogicExceptionNamingTheResourceRelationAndType(): void
    {
        try {
            $this->get(self::user(['agencies', 1], ['publishers', 1]), null);
            self::fail('Expected a LogicException.');
        } catch (\LogicException $e) {
            self::assertStringContainsString(IdentifiedUserResource::class, $e->getMessage());
            self::assertStringContainsString('"companies"', $e->getMessage());
            self::assertStringContainsString('"publishers"', $e->getMessage());
        }
    }

    #[Test]
    public function aMalformedPairIsALogicException(): void
    {
        $relation = new MorphToMany(new FieldState('companies', null), new RelationState(['agencies']), static fn(): array => [['agencies']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must return a list of [type, id] pairs');

        $relation->storedIdentifierList(new \stdClass(), self::request());
    }

    #[Test]
    public function aReturnThatIsNotAListIsALogicException(): void
    {
        $relation = new MorphToMany(new FieldState('companies', null), new RelationState(['agencies']), static fn(): string => 'agencies');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('returned string');

        $relation->storedIdentifierList(new \stdClass(), self::request());
    }

    #[Test]
    public function includedMembersAreReadOffTheParentAndRenderedInPairOrder(): void
    {
        $body = $this->get(self::user(['advertisers', 3], ['agencies', 7]), 'companies');

        self::assertSame([
            ['type' => 'advertisers', 'id' => 'adv-3'],
            ['type' => 'agencies', 'id' => '7', 'attributes' => ['name' => 'Agency 7']],
        ], self::included($body));
        self::assertSame(1, IdentifiedUserResource::$reads);
    }

    #[Test]
    public function aMemberTheParentDoesNotHoldStaysInTheLinkageButNotInIncluded(): void
    {
        $user = self::user(['agencies', 7], ['agencies', 8]);
        $user->companies = [self::agency(7, 'Agency 7')];

        $body = $this->get($user, 'companies');

        self::assertSame([['type' => 'agencies', 'id' => '7'], ['type' => 'agencies', 'id' => '8']], $body['data']['relationships']['companies']['data']);
        self::assertSame([['type' => 'agencies', 'id' => '7', 'attributes' => ['name' => 'Agency 7']]], self::included($body));
    }

    #[Test]
    public function aMemberOfAClassServingSeveralTypesRendersUnderItsPairType(): void
    {
        $user = self::user(['brands', 4], ['resellers', 5]);
        $user->companies = [new IdentifiedParty(4, 'Brand 4', 'emea'), new IdentifiedParty(5, 'Reseller 5', 'apac')];

        $body = $this->get($user, 'companies');

        self::assertSame([
            ['type' => 'brands', 'id' => '4', 'attributes' => ['name' => 'Brand 4']],
            ['type' => 'resellers', 'id' => '5', 'attributes' => ['name' => 'Reseller 5', 'region' => 'apac']],
        ], self::included($body));
    }

    #[Test]
    public function suppliedMembersReplaceThePairsAndTheirTargetsFillIncluded(): void
    {
        $seam = new SuppliedMembersLinkage([
            new IdentifiedMember('agencies', 7, self::agency(7, 'Loaded in a batch')),
            IdentifiedMember::of(['advertisers', 3], self::advertiser(3)),
        ]);

        $body = $this->get(self::user(['agencies', 7], ['agencies', 8], ['advertisers', 3]), 'companies', $seam);

        self::assertSame([['type' => 'agencies', 'id' => '7'], ['type' => 'advertisers', 'id' => 'adv-3']], $body['data']['relationships']['companies']['data']);
        self::assertSame([
            ['type' => 'agencies', 'id' => '7', 'attributes' => ['name' => 'Loaded in a batch']],
            ['type' => 'advertisers', 'id' => 'adv-3'],
        ], self::included($body));
        self::assertSame(0, IdentifiedUserResource::$reads);
    }

    #[Test]
    public function aSuppliedMemberWithoutATargetIsMatchedOnTheParentWhenIncluded(): void
    {
        $seam = new SuppliedMembersLinkage([new IdentifiedMember('agencies', 7)]);

        $body = $this->get(self::user(['agencies', 7], ['agencies', 8]), 'companies', $seam);

        self::assertSame([['type' => 'agencies', 'id' => '7']], $body['data']['relationships']['companies']['data']);
        self::assertSame([['type' => 'agencies', 'id' => '7', 'attributes' => ['name' => 'Agency 7']]], self::included($body));
    }

    #[Test]
    public function aSuppliedTargetOfAnotherTypeThanItsPairIsLeftOutOfIncluded(): void
    {
        $seam = new SuppliedMembersLinkage([new IdentifiedMember('agencies', 7, self::advertiser(7))]);

        $body = $this->get(self::user(['agencies', 7]), 'companies', $seam);

        self::assertSame([['type' => 'agencies', 'id' => '7']], $body['data']['relationships']['companies']['data']);
        self::assertSame([], self::included($body));
    }

    #[Test]
    public function aSuppliedValueThatIsNotAnIdentifiedMemberIsALogicException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(IdentifiedMember::class);

        $this->get(self::user(['agencies', 7]), null, new SuppliedMembersLinkage([self::agency(7, 'Agency 7')]));
    }

    #[Test]
    public function aSuppliedMemberOfAnUndeclaredTypeIsALogicException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"publishers"');

        $this->get(self::user(['agencies', 7]), null, new SuppliedMembersLinkage([new IdentifiedMember('publishers', 7)]));
    }

    #[Test]
    public function aSparseFieldsetLeavingTheRelationOutReadsNothing(): void
    {
        $body = $this->get(self::user(['agencies', 7]), null, fields: ['users' => 'name']);

        self::assertArrayNotHasKey('relationships', $body['data']);
        self::assertSame(0, IdentifiedUserResource::$reads);
    }

    #[Test]
    public function aSparseFieldsetNarrowsAnIncludedMembersAttributes(): void
    {
        $user = self::user(['brands', 4], ['resellers', 5]);
        $user->companies = [new IdentifiedParty(4, 'Brand 4', 'emea'), new IdentifiedParty(5, 'Reseller 5', 'apac')];

        $body = $this->get($user, 'companies', fields: ['resellers' => 'region']);

        self::assertSame([
            ['type' => 'brands', 'id' => '4', 'attributes' => ['name' => 'Brand 4']],
            ['type' => 'resellers', 'id' => '5', 'attributes' => ['region' => 'apac']],
        ], self::included($body));
    }

    #[Test]
    public function aCountFromTheHostRendersBesideThePairs(): void
    {
        $count = new class implements RelationshipCountInterface {
            public function countRelationship(mixed $model, RelationInterface $relation): int
            {
                return 1;
            }
        };

        $body = $this->get(self::user(['agencies', 7], ['agencies', 8]), null, new SuppliedMembersLinkage([new IdentifiedMember('agencies', 8)]), count: $count, withCount: 'companies');

        self::assertSame(['total' => 1], $body['data']['relationships']['companies']['meta']);
        self::assertSame([['type' => 'agencies', 'id' => '8']], $body['data']['relationships']['companies']['data']);
    }

    #[Test]
    public function theRelationshipEndpointRendersThePairs(): void
    {
        $body = $this->handle(
            self::user(['agencies', 7], ['advertisers', 3]),
            null,
            '/users/1/relationships/companies',
            static fn(object $user, Server $server): IdentifierResponse => IdentifierResponse::forRelationship($user, $server->serializerFor('users'), 'companies'),
        );

        self::assertSame([['type' => 'agencies', 'id' => '7'], ['type' => 'advertisers', 'id' => 'adv-3']], $body['data']);
        self::assertSame(0, IdentifiedUserResource::$reads);
    }

    #[Test]
    public function storedIdentifiersGroupsEveryParentsIdsByDeclaredTypeAndDedupes(): void
    {
        $relation = self::relation();

        $grouped = $relation->storedIdentifiers([
            self::user(['agencies', 7], ['advertisers', 3]),
            self::user(),
            self::user(['agencies', 7], ['agencies', '9'], ['advertisers', '3']),
        ], self::request());

        self::assertSame(['advertisers' => [3], 'agencies' => [7, '9']], $grouped);
        self::assertSame([['agencies', 7], ['agencies', '9'], ['advertisers', '3']], $relation->storedIdentifierList(self::user(['agencies', 7], ['agencies', '9'], ['advertisers', '3']), self::request()));
        self::assertSame([], $relation->storedIdentifiers([], self::request()));
    }

    #[Test]
    public function aRelationWithoutIdentifiedByStoresNoIdentifiers(): void
    {
        $plain = MorphToMany::make('companies', ['agencies'])->build();

        self::assertFalse($plain->storesIdentifiers());
        self::assertTrue(self::relation()->storesIdentifiers());
        self::assertInstanceOf(StoresIdentifiersInterface::class, MorphTo::make('target', ['agencies'])->build());

        $this->expectException(\LogicException::class);
        $plain->storedIdentifierList(new \stdClass(), self::request());
    }

    #[Test]
    public function anIdentifiedRelationIsNeverLoadAware(): void
    {
        self::assertFalse(self::relation()->emitsDataOnlyWhenLoaded());
        self::assertTrue(MorphToMany::make('companies', ['agencies'])->build()->emitsDataOnlyWhenLoaded());
    }

    #[Test]
    public function identifierMetaCannotBeCombinedWithIdentifiedBy(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('identifierMeta()');

        MorphToMany::make('companies', ['agencies'])
            ->identifiedBy(static fn(): array => [])
            ->identifierMeta(static fn(): array => [])
            ->build();
    }

    private static function relation(): MorphToMany
    {
        $relation = (new IdentifiedUserResource())->relationNamedIncludingHidden('companies');
        self::assertInstanceOf(MorphToMany::class, $relation);

        return $relation;
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{data: array{relationships: array<string, array<string, mixed>>}, included?: list<array{type: string, id: string, attributes?: array<string, mixed>}>}
     */
    private function get(
        object $user,
        ?string $include,
        ?RelationshipLinkageInterface $seam = null,
        ?RelationshipLoadStateInterface $loadState = null,
        array $fields = [],
        ?RelationshipCountInterface $count = null,
        ?string $withCount = null,
    ): array {
        $query = [];
        if ($include !== null) {
            $query['include'] = $include;
        }
        if ($fields !== []) {
            $query['fields'] = $fields;
        }
        if ($withCount !== null) {
            $query['withCount'] = $withCount;
        }

        /** @var array{data: array{relationships: array<string, array<string, mixed>>}, included?: list<array{type: string, id: string, attributes?: array<string, mixed>}>} */
        return $this->handle(
            $user,
            $seam,
            '/users/1',
            static fn(object $user, Server $server): DataResponse => DataResponse::fromResource($user, $server->serializerFor('users')),
            $query,
            $loadState,
            $count,
        );
    }

    /**
     * @param \Closure(object, Server): (DataResponse|IdentifierResponse) $respond
     * @param array<string, mixed>                                        $query
     *
     * @return array<string, mixed>
     */
    private function handle(
        object $user,
        ?RelationshipLinkageInterface $seam,
        string $uri,
        \Closure $respond,
        array $query = [],
        ?RelationshipLoadStateInterface $loadState = null,
        ?RelationshipCountInterface $count = null,
    ): array {
        $psr17 = new Psr17Factory();
        $server = Server::make()
            ->register(IdentifiedUserResource::class)
            ->register(IdentifiedAgencyResource::class)
            ->register(IdentifiedAdvertiserResource::class)
            ->register(IdentifiedBrandResource::class)
            ->register(IdentifiedResellerResource::class)
            ->withRelationshipLinkage($seam)
            ->withRelationshipLoadState($loadState)
            ->withRelationshipCount($count)
            ->withProfile(new CountableProfile());

        $handler = new class ($user, $respond) implements OperationHandlerInterface {
            /**
             * @param \Closure(object, Server): (DataResponse|IdentifierResponse) $respond
             */
            public function __construct(private object $user, private \Closure $respond) {}

            public function handle(JsonApiOperationInterface $operation): DataResponse|IdentifierResponse
            {
                $server = $operation->context()->server;
                \assert($server instanceof Server);

                return ($this->respond)($this->user, $server);
            }
        };

        $target = \str_contains($uri, '/relationships/')
            ? new Target('users', '1', \explode('/relationships/', $uri)[1], true)
            : new Target('users', '1');

        $request = (new ServerRequest('GET', $query === [] ? $uri : $uri . '?' . \http_build_query($query)))
            ->withAttribute(Target::class, $target)
            ->withQueryParams($query);
        if (isset($query['withCount'])) {
            $request = $request->withHeader('Accept', 'application/vnd.api+json;profile="' . CountableProfile::URI . '"');
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
        return new JsonApiRequest(new ServerRequest('GET', '/users'));
    }

    /**
     * @param array{0: string, 1: string|int} ...$pairs
     */
    private static function user(array ...$pairs): \stdClass
    {
        $companies = [];
        foreach ($pairs as [$type, $id]) {
            $companies[] = match ($type) {
                'agencies' => self::agency((int) $id, 'Agency ' . $id),
                'advertisers' => self::advertiser((int) $id),
                default => null,
            };
        }

        return (object) ['id' => '1', 'name' => 'Ada', 'pairs' => \array_values($pairs), 'companies' => \array_values(\array_filter($companies))];
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

final class IdentifiedUserResource extends AbstractResource
{
    public static string $type = 'users';

    public static int $reads = 0;

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            MorphToMany::make('companies', ['advertisers', 'agencies', 'brands', 'resellers'])
                ->identifiedBy(static fn(mixed $user): array => $user instanceof \stdClass && \is_array($user->pairs) ? $user->pairs : [])
                ->countable()
                ->extractUsing(static function (mixed $user): mixed {
                    ++self::$reads;

                    return $user instanceof \stdClass ? $user->companies : [];
                }),
        ];
    }
}

final class SuppliedMembersLinkage implements RelationshipLinkageInterface
{
    /**
     * @param list<mixed> $members
     */
    public function __construct(private readonly array $members) {}

    public function linkageForRelationship(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): RelationshipLinkage
    {
        return new RelationshipLinkage($this->members);
    }
}
