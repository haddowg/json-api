<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Internal\ToOneTarget;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
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
 * A host-supplied to-one target, rendered end to end through {@see Server::handle()}:
 * it replaces what the parent holds in the linkage, in `included` and through an
 * `on()` hop, a supplied none renders as an empty relation, and without the seam
 * nothing changes.
 */
#[CoversClass(ToOneTarget::class)]
#[CoversClass(ToOneRelationshipLinkage::class)]
#[CoversClass(MorphTo::class)]
#[CoversClass(AbstractResource::class)]
final class ToOneRelationshipLinkageTest extends TestCase
{
    #[Test]
    public function aSuppliedTargetReplacesTheParentsInLinkageIncludedAndFlattenedAttributes(): void
    {
        $article = self::article();
        $seam = new FakeToOneLinkage(['author' => ToOneRelationshipLinkage::to(self::person('2', 'Bob'))]);

        $body = $this->get($article, '/articles/1', 'author', $seam);

        self::assertSame(['type' => 'people', 'id' => '2'], $body['data']['relationships']['author']['data']);
        self::assertSame('Bob', $body['data']['attributes']['authorName']);
        self::assertSame([['type' => 'people', 'id' => '2', 'name' => 'Bob']], self::included($body));

        // The seam saw the parent and the relation, and nothing was written onto the parent.
        self::assertContains(['author', $article], $seam->calls);
        self::assertSame('Ann', $article->author->name);
    }

    #[Test]
    public function aSuppliedNoneRendersNullAndLeavesTheTargetOutOfIncluded(): void
    {
        $article = self::article();
        $seam = new FakeToOneLinkage(['author' => ToOneRelationshipLinkage::none()]);

        $body = $this->get($article, '/articles/1', 'author', $seam);

        self::assertArrayHasKey('data', $body['data']['relationships']['author']);
        self::assertNull($body['data']['relationships']['author']['data']);
        self::assertNull($body['data']['attributes']['authorName']);
        self::assertSame([], self::included($body));
        self::assertSame('1', $article->author->id);
    }

    #[Test]
    public function aFlattenedAttributeReadsEachHopThroughTheSeam(): void
    {
        $substituted = $this->get(
            self::article(),
            '/articles/1',
            null,
            new FakeToOneLinkage(['address' => ToOneRelationshipLinkage::to((object) ['id' => '8', 'city' => 'Leeds'])]),
        );
        self::assertSame('Leeds', $substituted['data']['attributes']['authorCity']);
        self::assertSame('Ann', $substituted['data']['attributes']['authorName']);

        $none = $this->get(self::article(), '/articles/1', null, new FakeToOneLinkage(['address' => ToOneRelationshipLinkage::none()]));
        self::assertNull($none['data']['attributes']['authorCity']);
        self::assertSame('Ann', $none['data']['attributes']['authorName']);
    }

    #[Test]
    public function aHiddenHopIsReadThroughTheSeamToo(): void
    {
        $body = $this->get(self::article(), '/articles/1', null, new FakeToOneLinkage(['editor' => ToOneRelationshipLinkage::none()]));

        self::assertNull($body['data']['attributes']['editorName']);
        self::assertArrayNotHasKey('editor', $body['data']['relationships']);
    }

    #[Test]
    public function aMorphToRendersASuppliedTargetOfAnotherDeclaredType(): void
    {
        $body = $this->get(
            self::article(),
            '/articles/1',
            'subject',
            new FakeToOneLinkage(['subject' => ToOneRelationshipLinkage::to(self::team('5', 'Core'))]),
        );

        self::assertSame(['type' => 'teams', 'id' => '5'], $body['data']['relationships']['subject']['data']);
        self::assertSame([['type' => 'teams', 'id' => '5', 'name' => 'Core']], self::included($body));
    }

    #[Test]
    public function aMorphToRendersASuppliedNoneAsNull(): void
    {
        $body = $this->get(self::article(), '/articles/1', 'subject', new FakeToOneLinkage(['subject' => ToOneRelationshipLinkage::none()]));

        self::assertArrayHasKey('data', $body['data']['relationships']['subject']);
        self::assertNull($body['data']['relationships']['subject']['data']);
        self::assertSame([], self::included($body));
    }

    #[Test]
    public function aMorphToTargetOfNoDeclaredTypeRendersAsNone(): void
    {
        $stranger = new FlattenedStranger();
        $body = $this->get(self::article(), '/articles/1', 'subject', new FakeToOneLinkage(['subject' => ToOneRelationshipLinkage::to($stranger)]));

        self::assertNull($body['data']['relationships']['subject']['data']);
        self::assertSame([], self::included($body));
    }

    #[Test]
    public function theRelationshipEndpointRendersTheSuppliedLinkage(): void
    {
        $none = $this->handle(
            self::article(),
            new FakeToOneLinkage(['author' => ToOneRelationshipLinkage::none()]),
            '/articles/1/relationships/author',
            static fn(object $article, Server $server): IdentifierResponse => IdentifierResponse::forRelationship($article, $server->serializerFor('articles'), 'author'),
        );
        self::assertNull($none['data']);

        $swapped = $this->handle(
            self::article(),
            new FakeToOneLinkage(['subject' => ToOneRelationshipLinkage::to(self::team('5', 'Core'))]),
            '/articles/1/relationships/subject',
            static fn(object $article, Server $server): IdentifierResponse => IdentifierResponse::forRelationship($article, $server->serializerFor('articles'), 'subject'),
        );
        self::assertSame(['type' => 'teams', 'id' => '5'], $swapped['data']);
    }

    #[Test]
    public function aDeferredRelationConsultsTheSeamOnlyWhenItRenders(): void
    {
        $seam = new FakeToOneLinkage(['profile' => ToOneRelationshipLinkage::to((object) ['id' => '30', 'bio' => 'Supplied'])]);
        $notLoaded = new class implements RelationshipLoadStateInterface {
            public function isRelationshipLoaded(mixed $model, RelationInterface $relation): bool
            {
                return false;
            }
        };

        $omitted = $this->get(self::article(), '/articles/1', null, $seam, $notLoaded);
        self::assertArrayNotHasKey('data', $omitted['data']['relationships']['profile']);
        self::assertNotContains('profile', \array_column($seam->calls, 0));

        $included = $this->get(self::article(), '/articles/1', 'profile', $seam, $notLoaded);
        self::assertSame(['type' => 'profiles', 'id' => '30'], $included['data']['relationships']['profile']['data']);
        self::assertSame([['type' => 'profiles', 'id' => '30', 'bio' => 'Supplied']], self::included($included));
    }

    #[Test]
    public function aWriteThroughASuppliedNoneHopIsRefusedLikeANullHop(): void
    {
        $article = self::article();
        $server = self::server(new FakeToOneLinkage(['author' => ToOneRelationshipLinkage::none()]));
        $request = new JsonApiRequest(
            (new ServerRequest('PATCH', '/articles/1'))->withParsedBody([
                'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['authorName' => 'Eve']],
            ]),
        );

        try {
            $server->hydratorFor('articles')->hydrate($request, $article);
            self::fail('Expected RelatedAttributeOwnerMissing.');
        } catch (\haddowg\JsonApi\Exception\RelatedAttributeOwnerMissing) {
        }

        self::assertSame('Ann', $article->author->name);
    }

    #[Test]
    public function aWriteThroughASuppliedHopLandsOnTheSuppliedTarget(): void
    {
        $article = self::article();
        $supplied = self::person('2', 'Bob');
        $server = self::server(new FakeToOneLinkage(['author' => ToOneRelationshipLinkage::to($supplied)]));
        $request = new JsonApiRequest(
            (new ServerRequest('PATCH', '/articles/1'))->withParsedBody([
                'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['authorName' => 'Eve']],
            ]),
        );

        $server->hydratorFor('articles')->hydrate($request, $article);

        self::assertSame('Eve', $supplied->name);
        self::assertSame('Ann', $article->author->name);
    }

    #[Test]
    public function withoutTheSeamTheParentIsReadAsBefore(): void
    {
        foreach ([null, new ToManyOnlyLinkage()] as $seam) {
            $body = $this->get(self::article(), '/articles/1', 'author,subject,profile', $seam);

            self::assertSame(['type' => 'people', 'id' => '1'], $body['data']['relationships']['author']['data']);
            self::assertSame(['type' => 'people', 'id' => '1'], $body['data']['relationships']['subject']['data']);
            self::assertSame(['type' => 'profiles', 'id' => '3'], $body['data']['relationships']['profile']['data']);
            self::assertSame('Ann', $body['data']['attributes']['authorName']);
            self::assertSame('York', $body['data']['attributes']['authorCity']);
            self::assertSame('Ed', $body['data']['attributes']['editorName']);
            self::assertSame(
                [
                    ['type' => 'people', 'id' => '1', 'name' => 'Ann'],
                    ['type' => 'profiles', 'id' => '3', 'bio' => 'Own'],
                ],
                self::included($body),
            );
        }
    }

    #[Test]
    public function aSeamWithNothingToSayFallsBackToTheParent(): void
    {
        $body = $this->get(self::article(), '/articles/1', 'author', new FakeToOneLinkage([]));

        self::assertSame(['type' => 'people', 'id' => '1'], $body['data']['relationships']['author']['data']);
        self::assertSame('Ann', $body['data']['attributes']['authorName']);
    }

    /**
     * @return array{data: array{attributes: array<string, mixed>, relationships: array<string, array<string, mixed>>}, included?: list<array{type: string, id: string, attributes?: array<string, mixed>}>}
     */
    private function get(
        object $article,
        string $path,
        ?string $include,
        ?RelationshipLinkageInterface $seam,
        ?RelationshipLoadStateInterface $loadState = null,
    ): array {
        /** @var array{data: array{attributes: array<string, mixed>, relationships: array<string, array<string, mixed>>}, included?: list<array{type: string, id: string, attributes?: array<string, mixed>}>} */
        return $this->handle(
            $article,
            $seam,
            $include === null ? $path : $path . '?include=' . $include,
            static fn(object $article, Server $server): DataResponse => DataResponse::fromResource($article, $server->serializerFor('articles')),
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
        object $article,
        ?RelationshipLinkageInterface $seam,
        string $uri,
        \Closure $respond,
        ?string $include = null,
        ?RelationshipLoadStateInterface $loadState = null,
    ): array {
        $psr17 = new Psr17Factory();
        $server = self::server($seam)->withRelationshipLoadState($loadState);

        $handler = new class ($article, $respond) implements OperationHandlerInterface {
            /**
             * @param \Closure(object, Server): (DataResponse|IdentifierResponse) $respond
             */
            public function __construct(private object $article, private \Closure $respond) {}

            public function handle(JsonApiOperationInterface $operation): DataResponse|IdentifierResponse
            {
                $server = $operation->context()->server;
                \assert($server instanceof Server);

                return ($this->respond)($this->article, $server);
            }
        };

        $target = \str_contains($uri, '/relationships/')
            ? new Target('articles', '1', \explode('/relationships/', $uri)[1], true)
            : new Target('articles', '1');

        $request = (new ServerRequest('GET', $uri))->withAttribute(Target::class, $target);
        if ($include !== null) {
            $request = $request->withQueryParams(['include' => $include]);
        }

        $response = $server->withPsr17($psr17, $psr17)->withHandler($handler)->handle($request);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array<string, mixed> $body */
        $body = \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);

        return $body;
    }

    private static function server(?RelationshipLinkageInterface $seam): Server
    {
        return Server::make()
            ->register(LinkageArticleResource::class)
            ->register(LinkagePersonResource::class)
            ->register(LinkageTeamResource::class)
            ->register(LinkageProfileResource::class)
            ->withRelationshipLinkage($seam);
    }

    /**
     * @param array{included?: list<array{type: string, id: string, attributes?: array<string, mixed>}>} $body
     *
     * @return list<array<string, mixed>>
     */
    private static function included(array $body): array
    {
        $included = $body['included'] ?? [];

        return \array_map(
            static fn(array $resource): array => ['type' => $resource['type'], 'id' => $resource['id'], ...($resource['attributes'] ?? [])],
            $included,
        );
    }

    private static function article(): \stdClass
    {
        $author = self::person('1', 'Ann');
        $author->address = (object) ['id' => '4', 'city' => 'York'];

        return (object) [
            'id' => '1',
            'title' => 'Hello',
            'author' => $author,
            'subject' => $author,
            'editor' => self::person('6', 'Ed'),
            'profile' => (object) ['id' => '3', 'bio' => 'Own'],
        ];
    }

    private static function person(string $id, string $name): \stdClass
    {
        return (object) ['id' => $id, 'name' => $name, 'kind' => 'person', 'address' => null];
    }

    private static function team(string $id, string $name): \stdClass
    {
        return (object) ['id' => $id, 'name' => $name, 'kind' => 'team'];
    }
}

final class FakeToOneLinkage implements ToOneRelationshipLinkageInterface
{
    /** @var list<array{string, mixed}> */
    public array $calls = [];

    /**
     * @param array<string, ToOneRelationshipLinkage> $byRelation
     */
    public function __construct(private readonly array $byRelation) {}

    public function linkageForRelationship(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): ?RelationshipLinkage
    {
        return null;
    }

    public function toOneLinkageForRelationship(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): ?ToOneRelationshipLinkage
    {
        $this->calls[] = [$relation->name(), $model];

        return $this->byRelation[$relation->name()] ?? null;
    }
}

final class ToManyOnlyLinkage implements RelationshipLinkageInterface
{
    public function linkageForRelationship(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): ?RelationshipLinkage
    {
        return null;
    }
}

final class FlattenedStranger
{
    public string $id = '99';
    public string $kind = 'stranger';
}

final class LinkageArticleResource extends AbstractResource
{
    public static string $type = 'articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('authorName')->on('author')->storedAs('name'),
            Str::make('authorCity')->on('author.address')->storedAs('city'),
            Str::make('editorName')->on('editor')->storedAs('name'),
            BelongsTo::make('author', 'people'),
            BelongsTo::make('editor', 'people')->hidden(),
            MorphTo::make('subject', ['people', 'teams']),
            HasOne::make('profile', 'profiles'),
        ];
    }
}

final class LinkagePersonResource extends AbstractResource
{
    public static string $type = 'people';

    public function getType(mixed $object): string
    {
        return \is_object($object) && ($object->kind ?? null) === 'person' ? 'people' : 'unknown';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('address', 'addresses')->hidden(),
        ];
    }
}

final class LinkageTeamResource extends AbstractResource
{
    public static string $type = 'teams';

    public function getType(mixed $object): string
    {
        return \is_object($object) && ($object->kind ?? null) === 'team' ? 'teams' : 'unknown';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}

final class LinkageProfileResource extends AbstractResource
{
    public static string $type = 'profiles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('bio'),
        ];
    }
}
