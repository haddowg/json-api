<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Exception\AccessDenied;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\RelationAuthorizerInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Serializer\RelationshipCountInterface;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A server's relation authorizer decides, per parent and relation, whether a rendered
 * relationship carries its data and its included resources, and whether a flattened
 * `on()` attribute may be written onto its related model.
 */
#[CoversClass(Server::class)]
#[CoversClass(AbstractResource::class)]
final class RelationAuthorizerTest extends TestCase
{
    #[Test]
    public function aDeniedRelationshipKeepsItsLinksButRendersNoData(): void
    {
        $body = $this->fetch('', new DenyRelations(['secret', 'watchers', 'subject', 'mentions']));

        self::assertSame(['type' => 'ra-people', 'id' => '3'], self::dig($body, 'data', 'relationships', 'author', 'data'));
        foreach (['secret', 'watchers', 'subject', 'mentions'] as $name) {
            $relationship = self::dig($body, 'data', 'relationships', $name);
            self::assertIsArray($relationship, $name);
            self::assertArrayNotHasKey('data', $relationship, $name);
            self::assertIsArray(self::dig($relationship, 'links', 'related'), $name);
        }
    }

    #[Test]
    public function aDeniedRelationshipContributesNothingToIncluded(): void
    {
        $body = $this->fetch('?include=author,secret,watchers,subject,mentions', new DenyRelations(['secret', 'watchers', 'subject', 'mentions']));

        self::assertSame(['3'], self::includedIds($body));
    }

    #[Test]
    public function theAuthorizerIsAskedPerParentSoANestedIncludeIsPrunedToo(): void
    {
        $body = $this->fetch('?include=author.friend', new DenyRelations(['friend']));

        self::assertSame(['3'], self::includedIds($body));
        $friend = self::dig($body, 'included', 0, 'relationships', 'friend');
        self::assertIsArray($friend);
        self::assertArrayNotHasKey('data', $friend);
    }

    #[Test]
    public function aDeniedRelationshipWithNoLinksIsLeftOut(): void
    {
        $relationships = self::dig($this->fetch('', new DenyRelations(['unlinked'])), 'data', 'relationships');

        self::assertIsArray($relationships);
        self::assertArrayNotHasKey('unlinked', $relationships);
        self::assertArrayHasKey('author', $relationships);
    }

    #[Test]
    public function aDeniedRelationshipRendersNoCount(): void
    {
        $watchers = self::dig($this->fetch('?withCount=watchers', new DenyRelations(['watchers']), CountableProfile::URI), 'data', 'relationships', 'watchers');

        self::assertIsArray($watchers);
        self::assertArrayNotHasKey('meta', $watchers);
        self::assertSame(['total' => 2], self::dig($this->fetch('?withCount=watchers', new DenyRelations([]), CountableProfile::URI), 'data', 'relationships', 'watchers', 'meta'));
    }

    #[Test]
    public function withoutAnAuthorizerEveryRelationshipRendersAsBefore(): void
    {
        $body = $this->fetch('?include=secret,watchers', null);

        self::assertSame(['type' => 'ra-people', 'id' => '4'], self::dig($body, 'data', 'relationships', 'secret', 'data'));
        self::assertSame(['4', '5'], self::includedIds($body));
    }

    #[Test]
    public function aFlattenedWriteOntoARelatedModelIsAskedForFirst(): void
    {
        $authorizer = new DenyRelations([], denyWrites: true);
        $resource = $this->server($authorizer)->resourceFor('ra-articles');
        $article = RaStore::article();

        try {
            $resource->hydrate($this->patch(['authorName' => 'Grace']), $article);
            self::fail('expected AccessDenied');
        } catch (AccessDenied $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }

        self::assertSame('Ann', $article->author->name);
        self::assertCount(1, $authorizer->writes);
        [$owner, $relation, $field, $parent] = $authorizer->writes[0];
        self::assertSame($article->author, $owner);
        self::assertSame('author', $relation->name());
        self::assertSame('authorName', $field->name());
        self::assertSame($article, $parent);
    }

    #[Test]
    public function anAllowedFlattenedWriteLands(): void
    {
        $article = RaStore::article();

        $this->server(new DenyRelations([]))->resourceFor('ra-articles')->hydrate($this->patch(['authorName' => 'Grace']), $article);

        self::assertSame('Grace', $article->author->name);
    }

    private static function dig(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     * @return list<mixed>
     */
    private static function includedIds(array $body): array
    {
        $included = $body['included'] ?? [];

        return \is_array($included) ? \array_values(\array_map(static fn(mixed $resource): mixed => self::dig($resource, 'id'), $included)) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $query, ?RelationAuthorizerInterface $authorizer, ?string $profile = null): array
    {
        \parse_str(\ltrim($query, '?'), $params);
        $mediaType = 'application/vnd.api+json' . ($profile !== null ? '; profile="' . $profile . '"' : '');
        $request = (new ServerRequest('GET', '/ra-articles/1' . $query, ['Accept' => $mediaType]))
            ->withQueryParams($params)
            ->withAttribute(Target::class, new Target('ra-articles', '1'));

        $response = $this->server($authorizer)->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array<string, mixed> */
        return \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function server(?RelationAuthorizerInterface $authorizer): Server
    {
        $psr17 = new Psr17Factory();

        return Server::make()
            ->withPsr17($psr17, $psr17)
            ->withProfile(new CountableProfile())
            ->withRelationshipCount(new RaCount())
            ->withRelationAuthorizer($authorizer)
            ->register(RaArticle::class)
            ->register(RaPerson::class)
            ->withHandler(new RaHandler());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function patch(array $attributes): JsonApiRequest
    {
        return new JsonApiRequest(
            (new ServerRequest('PATCH', '/ra-articles/1', ['Content-Type' => 'application/vnd.api+json']))
                ->withParsedBody(['data' => ['type' => 'ra-articles', 'id' => '1', 'attributes' => $attributes]]),
        );
    }
}

final class DenyRelations implements RelationAuthorizerInterface
{
    /** @var list<array{mixed, RelationInterface, FieldInterface, mixed}> */
    public array $writes = [];

    /**
     * @param list<string> $denied
     */
    public function __construct(private readonly array $denied, private readonly bool $denyWrites = false) {}

    public function canReadRelationship(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): bool
    {
        return !\in_array($relation->name(), $this->denied, true);
    }

    public function canWriteRelatedAttribute(mixed $owner, RelationInterface $relation, FieldInterface $field, mixed $model, JsonApiRequestInterface $request): bool
    {
        $this->writes[] = [$owner, $relation, $field, $model];

        return !$this->denyWrites;
    }
}

final class RaCount implements RelationshipCountInterface
{
    public function countRelationship(mixed $model, RelationInterface $relation): int
    {
        return 2;
    }
}

final class RaStore
{
    public static function article(): \stdClass
    {
        $friend = (object) ['id' => '6', 'name' => 'Fay', 'friend' => null];
        $person = static fn(string $id, string $name): \stdClass => (object) ['id' => $id, 'name' => $name, 'friend' => $friend];
        $author = $person('3', 'Ann');
        $secret = $person('4', 'Sam');
        $watcher = $person('5', 'Wes');

        return (object) [
            'id' => '1',
            'title' => 'Hello',
            'author' => $author,
            'secret' => $secret,
            'watchers' => [$secret, $watcher],
            'subject' => $secret,
            'mentions' => [$watcher],
            'unlinked' => $secret,
        ];
    }
}

final readonly class RaHandler implements OperationHandlerInterface
{
    public function handle(JsonApiOperationInterface $operation): DataResponse
    {
        return DataResponse::fromResource(RaStore::article(), $operation->context()->server->serializerFor('ra-articles'));
    }
}

final class RaArticle extends AbstractResource
{
    public static string $type = 'ra-articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('authorName')->on('author')->storedAs('name'),
            BelongsTo::make('author', 'ra-people'),
            BelongsTo::make('secret', 'ra-people')->security(read: 'is_granted("SECRET")'),
            HasMany::make('watchers', 'ra-people')->countable(),
            MorphTo::make('subject', ['ra-people']),
            MorphToMany::make('mentions', ['ra-people']),
            BelongsTo::make('unlinked', 'ra-people')->withoutLinks(),
        ];
    }
}

final class RaPerson extends AbstractResource
{
    public static string $type = 'ra-people';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('friend', 'ra-people'),
        ];
    }
}
