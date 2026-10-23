<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Middleware\ErrorHandlerMiddleware;
use haddowg\JsonApi\Operation\AddToRelationshipOperation;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchRelationshipOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Operation\UpdateRelationshipOperation;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Transformer\IncludePathGuard;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * A `writeOnly()` relation behaves like a write-only attribute: it is accepted on a
 * write and rendered nowhere. Driven end to end through {@see Server::handle()}.
 */
#[CoversClass(AbstractResource::class)]
#[CoversClass(IncludePathGuard::class)]
#[CoversClass(IdentifierResponse::class)]
#[CoversClass(Server::class)]
final class WriteOnlyRelationTest extends TestCase
{
    private WriteOnlyStore $store;

    protected function setUp(): void
    {
        $this->store = new WriteOnlyStore();
    }

    #[Test]
    public function aWriteOnlyToOneAndToManyAreAbsentFromAFetch(): void
    {
        $body = $this->json($this->send('GET', '/wo-articles/1', new Target('wo-articles', '1')));

        self::assertSame(['author', 'editor', 'followers', 'reviewer'], $this->relationshipNames($body['data'] ?? null));
    }

    #[Test]
    public function aWriteOnlyRelationIsAbsentFromAnIncludedResource(): void
    {
        $body = $this->json($this->send('GET', '/wo-articles/1?include=author', new Target('wo-articles', '1')));

        $included = \is_array($body['included'] ?? null) ? $body['included'][0] ?? null : null;
        self::assertIsArray($included);
        self::assertSame('wo-people', $included['type'] ?? null);
        self::assertSame(['friend'], $this->relationshipNames($included));
    }

    #[Test]
    public function aWriteOnlyRelationIsAbsentFromARelatedEndpointsPrimaryData(): void
    {
        $body = $this->json($this->send('GET', '/wo-articles/1/author', new Target('wo-articles', '1', 'author')));

        self::assertSame(['friend'], $this->relationshipNames($body['data'] ?? null));
    }

    #[Test]
    public function aWriteOnlyRelationCannotBeIncluded(): void
    {
        foreach (['approver', 'watchers', 'author.mentor'] as $path) {
            $response = $this->send('GET', '/wo-articles/1?include=' . $path, new Target('wo-articles', '1'));

            self::assertSame(400, $response->getStatusCode(), $path);
            self::assertSame('INCLUSION_UNRECOGNIZED', $this->errorCode($response), $path);
        }
    }

    #[Test]
    public function aWriteOnlyRelationIsRejectedOnAnEmptyCollectionToo(): void
    {
        $response = $this->send('GET', '/wo-articles?include=approver', new Target('wo-articles'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('INCLUSION_UNRECOGNIZED', $this->errorCode($response));
    }

    #[Test]
    public function aWriteOnlyRelationIsNotCountable(): void
    {
        $response = $this->send('GET', '/wo-articles/1?withCount=watchers', new Target('wo-articles', '1'), profile: CountableProfile::URI);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('RELATIONSHIP_COUNT_NOT_ALLOWED', $this->errorCode($response));

        // The readable twin is countable, so the rejection is the write-only flag.
        self::assertSame(200, $this->send('GET', '/wo-articles/1?withCount=followers', new Target('wo-articles', '1'), profile: CountableProfile::URI)->getStatusCode());
    }

    #[Test]
    public function aWriteOnlyRelationHasNoReadEndpoints(): void
    {
        foreach ([new Target('wo-articles', '1', 'approver'), new Target('wo-articles', '1', 'watchers', true)] as $target) {
            $response = $this->send('GET', '/wo-articles/1/whatever', $target);

            self::assertSame(404, $response->getStatusCode());
            self::assertSame('RELATIONSHIP_NOT_EXISTS', $this->errorCode($response));
        }
    }

    #[Test]
    public function aSparseFieldsetNamingAWriteOnlyRelationIsAcceptedButRendersNothing(): void
    {
        $response = $this->send('GET', '/wo-articles/1?fields[wo-articles]=title,approver', new Target('wo-articles', '1'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->relationshipNames($this->json($response)['data'] ?? null));
    }

    #[Test]
    public function aWriteOnlyRelationIsWritableOnCreateAndAbsentFromTheResponse(): void
    {
        $response = $this->send('POST', '/wo-articles', new Target('wo-articles'), [
            'data' => [
                'type' => 'wo-articles',
                'attributes' => ['title' => 'New'],
                'relationships' => [
                    'approver' => ['data' => ['type' => 'wo-people', 'id' => '7']],
                    'watchers' => ['data' => [['type' => 'wo-people', 'id' => '8'], ['type' => 'wo-people', 'id' => '9']]],
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('7', $this->store->written['approverId'] ?? null);
        self::assertSame(['8', '9'], $this->store->written['watcherIds'] ?? null);
        self::assertSame(['author', 'editor', 'followers', 'reviewer'], $this->relationshipNames($this->json($response)['data'] ?? null));
    }

    #[Test]
    public function aWriteOnlyRelationshipMutationAppliesAndAnswersNoContent(): void
    {
        $toOne = $this->send('PATCH', '/wo-articles/1/relationships/approver', new Target('wo-articles', '1', 'approver', true), [
            'data' => ['type' => 'wo-people', 'id' => '5'],
        ]);

        self::assertSame(204, $toOne->getStatusCode());
        self::assertSame('', (string) $toOne->getBody());
        self::assertSame('5', $this->store->written['approverId'] ?? null);

        $toMany = $this->send('POST', '/wo-articles/1/relationships/watchers', new Target('wo-articles', '1', 'watchers', true), [
            'data' => [['type' => 'wo-people', 'id' => '6']],
        ]);

        self::assertSame(204, $toMany->getStatusCode());
        self::assertSame(['2', '6'], $this->store->written['watcherIds'] ?? null);
    }

    #[Test]
    public function aReadableRelationshipMutationStillEchoesItsLinkage(): void
    {
        $response = $this->send('PATCH', '/wo-articles/1/relationships/editor', new Target('wo-articles', '1', 'editor', true), [
            'data' => ['type' => 'wo-people', 'id' => '5'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['type' => 'wo-people', 'id' => '5'], $this->json($response)['data']);
    }

    #[Test]
    public function thePredicateFormAppliesPerRequest(): void
    {
        $guest = $this->send('GET', '/wo-articles/1?include=reviewer', new Target('wo-articles', '1'), role: 'guest');
        self::assertSame(200, $guest->getStatusCode());
        self::assertContains('reviewer', $this->relationshipNames($this->json($guest)['data'] ?? null));

        $admin = $this->send('GET', '/wo-articles/1', new Target('wo-articles', '1'), role: 'admin');
        self::assertNotContains('reviewer', $this->relationshipNames($this->json($admin)['data'] ?? null));

        $adminInclude = $this->send('GET', '/wo-articles/1?include=reviewer', new Target('wo-articles', '1'), role: 'admin');
        self::assertSame(400, $adminInclude->getStatusCode());
        self::assertSame('INCLUSION_UNRECOGNIZED', $this->errorCode($adminInclude));

        self::assertSame(404, $this->send('GET', '/wo-articles/1/reviewer', new Target('wo-articles', '1', 'reviewer'), role: 'admin')->getStatusCode());
        self::assertSame(200, $this->send('GET', '/wo-articles/1/reviewer', new Target('wo-articles', '1', 'reviewer'), role: 'guest')->getStatusCode());

        $mutation = $this->send('PATCH', '/wo-articles/1/relationships/reviewer', new Target('wo-articles', '1', 'reviewer', true), [
            'data' => ['type' => 'wo-people', 'id' => '4'],
        ], role: 'admin');
        self::assertSame(204, $mutation->getStatusCode());
        self::assertSame('4', $this->store->written['reviewerId'] ?? null);
    }

    #[Test]
    public function theStaticGettersReportAnUnconditionalWriteOnlyRelationAsUnreadable(): void
    {
        $approver = (new WoArticle())->relationNamed('approver');
        $watchers = (new WoArticle())->relationNamed('watchers');
        $reviewer = (new WoArticle())->relationNamed('reviewer');
        self::assertNotNull($approver);
        self::assertNotNull($watchers);
        self::assertNotNull($reviewer);

        self::assertFalse($approver->isIncludable());
        self::assertFalse($approver->exposesRelatedEndpoint());
        self::assertTrue($approver->exposesRelationshipEndpoint());
        self::assertFalse($watchers->isCountable());

        // A predicate leaves the static facts permissive, the superset the document describes.
        self::assertTrue($reviewer->isIncludable());
        self::assertTrue($reviewer->exposesRelatedEndpoint());
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $uri, Target $target, ?array $body = null, string $role = 'guest', ?string $profile = null): ResponseInterface
    {
        $parts = \parse_url($uri);
        \parse_str($parts['query'] ?? '', $query);

        $mediaType = 'application/vnd.api+json' . ($profile !== null ? '; profile="' . $profile . '"' : '');
        $request = (new ServerRequest($method, $uri, ['Accept' => $mediaType, 'X-Role' => $role]))
            ->withQueryParams($query)
            ->withAttribute(Target::class, $target);
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/vnd.api+json')
                ->withBody((new Psr17Factory())->createStream(\json_encode($body, \JSON_THROW_ON_ERROR)));
        }

        $psr17 = new Psr17Factory();
        $server = Server::make()
            ->withPsr17($psr17, $psr17)
            ->withProfile(new CountableProfile())
            ->register(WoArticle::class)
            ->register(WoPerson::class)
            ->withHandler(new WriteOnlyHandler($this->store));

        return $server->withMiddleware([new ErrorHandlerMiddleware($server)])->handle($request);
    }

    /**
     * @return list<string>
     */
    private function relationshipNames(mixed $resource): array
    {
        if (!\is_array($resource) || !\is_array($resource['relationships'] ?? null)) {
            return [];
        }

        return \array_map(\strval(...), \array_keys($resource['relationships']));
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

final class WriteOnlyStore
{
    /** @var array<mixed> */
    public array $written = [];

    /**
     * @return array<string, mixed>
     */
    public function article(): array
    {
        $person = ['id' => '3', 'name' => 'Ann', 'mentor' => null, 'friend' => null];

        return [
            'id' => '1',
            'title' => 'Hello',
            'author' => $person,
            'editor' => null,
            'approver' => $person,
            'approverId' => '3',
            'watchers' => [$person],
            'watcherIds' => ['2'],
            'followers' => [$person],
            'reviewer' => $person,
            'reviewerId' => '3',
        ];
    }
}

final readonly class WriteOnlyHandler implements OperationHandlerInterface
{
    public function __construct(private WriteOnlyStore $store) {}

    public function handle(JsonApiOperationInterface $operation): DataResponse|RelatedResponse|IdentifierResponse
    {
        $server = $operation->context()->server;
        $articles = $server->serializerFor('wo-articles');
        \assert($articles instanceof WoArticle);
        $article = $this->store->article();
        $relationship = $operation->target()->relationship ?? '';

        if ($operation instanceof CreateResourceOperation) {
            $created = $articles->hydrate($operation->body(), ['id' => '9']);
            \assert(\is_array($created));
            $this->store->written = $created;

            return DataResponse::fromResource([...$article, ...$created], $articles)->withStatus(201);
        }

        if ($operation instanceof UpdateRelationshipOperation || $operation instanceof AddToRelationshipOperation) {
            $updated = $articles->hydrateRelationship($relationship, $operation->body(), $article);
            \assert(\is_array($updated));
            $this->store->written = $updated;
            $updated['editor'] = $relationship === 'editor' ? ['id' => '5', 'name' => 'Eve', 'mentor' => null, 'friend' => null] : null;

            return IdentifierResponse::forRelationship($updated, $articles, $relationship);
        }

        if ($operation instanceof FetchRelatedOperation) {
            return RelatedResponse::fromResource($article[$relationship] ?? null, $server->serializerFor('wo-people'));
        }

        if ($operation instanceof FetchRelationshipOperation) {
            return IdentifierResponse::forRelationship($article, $articles, $relationship);
        }

        return $operation->target()->id === null
            ? DataResponse::fromCollection([], $articles)
            : DataResponse::fromResource($article, $articles);
    }
}

final class WoArticle extends AbstractResource
{
    public static string $type = 'wo-articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            BelongsTo::make('author', 'wo-people'),
            BelongsTo::make('editor', 'wo-people'),
            BelongsTo::make('approver', 'wo-people')->storedAs('approverId')->writeOnly(),
            HasMany::make('watchers', 'wo-people')->storedAs('watcherIds')->countable()->writeOnly(),
            HasMany::make('followers', 'wo-people')->countable(),
            BelongsTo::make('reviewer', 'wo-people')->storedAs('reviewerId')->writeOnly(
                static fn(JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') === 'admin',
            ),
        ];
    }
}

final class WoPerson extends AbstractResource
{
    public static string $type = 'wo-people';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('mentor', 'wo-people')->writeOnly(),
            BelongsTo::make('friend', 'wo-people'),
        ];
    }
}
