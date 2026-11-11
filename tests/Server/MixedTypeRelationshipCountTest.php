<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Middleware\ErrorHandlerMiddleware;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Schema\Document\AbstractResourceDocument;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Serializer\RelationshipCountInterface;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * `?withCount` on the related endpoint of a polymorphic to-many, whose page mixes
 * resource types: a name is accepted when any member type counts it, and each member
 * renders its own `meta.total`.
 */
#[CoversClass(PolymorphicSerializer::class)]
#[CoversClass(AbstractResourceDocument::class)]
final class MixedTypeRelationshipCountTest extends TestCase
{
    #[Test]
    public function aNameOneMemberTypeCountsRendersATotalOnThoseMembersOnly(): void
    {
        $count = new class implements RelationshipCountInterface {
            /** @var list<string> */
            public array $askedAbout = [];

            public function countRelationship(mixed $model, RelationInterface $relation): int
            {
                \assert($model instanceof \stdClass && \is_string($model->kind));
                $this->askedAbout[] = $model->kind . '.' . $relation->name();

                return 3;
            }
        };

        $response = $this->send('withCount=campaigns', $count);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'] ?? null;
        self::assertIsArray($data);

        $totals = [];
        foreach ($data as $member) {
            self::assertIsArray($member);
            $relationships = $member['relationships'] ?? [];
            self::assertIsArray($relationships);
            $campaigns = $relationships['campaigns'] ?? null;
            $meta = \is_array($campaigns) ? $campaigns['meta'] ?? null : null;
            $totals[] = [$member['type'] ?? null, \is_array($campaigns) ? (\is_array($meta) ? $meta['total'] ?? null : null) : 'absent'];
        }

        self::assertSame([['agencies', 3], ['publishers', 'absent'], ['advertisers', 3]], $totals);
        self::assertSame(['agency.campaigns', 'advertiser.campaigns'], $count->askedAbout);
    }

    #[Test]
    public function aNameNoMemberTypeCountsIs400(): void
    {
        // `sites` is a to-many on publishers, but not countable.
        $response = $this->send('withCount=sites');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('RELATIONSHIP_COUNT_NOT_ALLOWED', $this->errorCode($response));
    }

    #[Test]
    public function aNameCountedOnlyWhereItIsWriteOnlyForTheRequestIs400(): void
    {
        // Advertisers alone count `notes`, and it is write-only for an admin.
        self::assertSame(200, $this->send('withCount=notes', role: 'guest')->getStatusCode());

        $response = $this->send('withCount=notes', role: 'admin');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('RELATIONSHIP_COUNT_NOT_ALLOWED', $this->errorCode($response));
    }

    #[Test]
    public function theSelfTokenStaysGatedByTheOwningRelation(): void
    {
        self::assertSame(200, $this->send('withCount=_self_')->getStatusCode());
        self::assertSame(400, $this->send('withCount=_self_', selfCountable: false)->getStatusCode());
    }

    private function send(string $query, ?RelationshipCountInterface $count = null, string $role = 'guest', bool $selfCountable = true): ResponseInterface
    {
        \parse_str($query, $params);
        $request = (new ServerRequest('GET', '/accounts/1/companies?' . $query, [
            'Accept' => 'application/vnd.api+json; profile="' . CountableProfile::URI . '"',
            'X-Role' => $role,
        ]))
            ->withQueryParams($params)
            ->withAttribute(Target::class, new Target('accounts', '1', 'companies'));

        $handler = new class ($selfCountable) implements OperationHandlerInterface {
            public function __construct(private readonly bool $selfCountable) {}

            public function handle(JsonApiOperationInterface $operation): RelatedResponse
            {
                $server = $operation->context()->server;
                \assert($server instanceof Server);
                $account = $server->serializerFor('accounts');
                \assert($account instanceof AbstractResource);
                $relation = $account->relationNamed('companies');
                \assert($relation !== null);

                return RelatedResponse::fromCollection(
                    [
                        (object) ['id' => '1', 'kind' => 'agency', 'campaigns' => []],
                        (object) ['id' => '2', 'kind' => 'publisher', 'sites' => []],
                        (object) ['id' => '3', 'kind' => 'advertiser', 'campaigns' => [], 'notes' => []],
                    ],
                    PolymorphicSerializer::forRelation($relation, $server),
                    $this->selfCountable,
                );
            }
        };

        $psr17 = new Psr17Factory();
        $server = Server::make()
            ->withPsr17($psr17, $psr17)
            ->withProfile(new CountableProfile())
            ->register(MixedAccountResource::class)
            ->register(MixedAgencyResource::class)
            ->register(MixedAdvertiserResource::class)
            ->register(MixedPublisherResource::class)
            ->register(MixedCampaignResource::class)
            ->withRelationshipCount($count)
            ->withHandler($handler);

        return $server->withMiddleware([new ErrorHandlerMiddleware($server)])->handle($request);
    }

    private function errorCode(ResponseInterface $response): mixed
    {
        $errors = $this->json($response)['errors'] ?? null;

        return \is_array($errors) && \is_array($errors[0] ?? null) ? $errors[0]['code'] ?? null : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
    }
}

final class MixedAccountResource extends AbstractResource
{
    public static string $type = 'accounts';

    public function fields(): array
    {
        return [
            Id::make(),
            MorphToMany::make('companies', ['agencies', 'advertisers', 'publishers'])->countable(),
        ];
    }
}

final class MixedAgencyResource extends AbstractResource
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
            HasMany::make('campaigns', 'campaigns')->countable(),
        ];
    }
}

final class MixedAdvertiserResource extends AbstractResource
{
    public static string $type = 'advertisers';

    public function getType(mixed $object): string
    {
        return \is_object($object) && ($object->kind ?? null) === 'advertiser' ? 'advertisers' : 'unknown';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            HasMany::make('campaigns', 'campaigns')->countable(),
            HasMany::make('notes', 'campaigns')->countable()->writeOnly(
                static fn(JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') === 'admin',
            ),
        ];
    }
}

final class MixedPublisherResource extends AbstractResource
{
    public static string $type = 'publishers';

    public function getType(mixed $object): string
    {
        return \is_object($object) && ($object->kind ?? null) === 'publisher' ? 'publishers' : 'unknown';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            HasMany::make('sites', 'campaigns'),
        ];
    }
}

final class MixedCampaignResource extends AbstractResource
{
    public static string $type = 'campaigns';

    public function fields(): array
    {
        return [Id::make()];
    }
}
