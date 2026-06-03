<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Docs;

use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Middleware\ContentNegotiationMiddleware;
use haddowg\JsonApi\Middleware\ErrorHandlerMiddleware;
use haddowg\JsonApi\Middleware\RequestBodyParsingMiddleware;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperation;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Testing\JsonApiDocument;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The runnable backing for `docs/getting-started.md`. Everything a consumer wires
 * up — a domain model, an in-memory store, a schema, a PSR-15 handler, a tiny
 * path-prefix router, and the `Server` that holds them — lives here so the
 * documented quick-start is exercised on every CI run and cannot silently rot.
 *
 * Keep this file and `docs/getting-started.md` in step: the page quotes these
 * classes verbatim.
 */
#[Group('docs')]
final class GettingStartedExampleTest extends TestCase
{
    #[Test]
    public function fetchingASingleResource(): void
    {
        $server = $this->server();

        $request = new ServerRequest('GET', 'https://example.test/articles/1', [
            'Accept' => 'application/vnd.api+json',
        ]);

        $response = $server->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));

        JsonApiDocument::of($response)
            ->assertHasType('articles')
            ->assertHasId('1')
            ->assertHasAttribute('title', 'JSON:API in PHP')
            ->assertHasAttribute('body', 'A worked example.');
    }

    #[Test]
    public function fetchingACollection(): void
    {
        $server = $this->server();

        $request = new ServerRequest('GET', 'https://example.test/articles', [
            'Accept' => 'application/vnd.api+json',
        ]);

        $response = $server->handle($request);

        self::assertSame(200, $response->getStatusCode());

        $data = JsonApiDocument::of($response)->data();
        self::assertIsArray($data);
        self::assertCount(2, $data);
    }

    #[Test]
    public function creatingAResource(): void
    {
        $server = $this->server();

        $body = (string) \json_encode([
            'data' => [
                'type' => 'articles',
                'attributes' => [
                    'title' => 'A new article',
                    'body' => 'Created over HTTP.',
                ],
            ],
        ]);

        $request = (new ServerRequest('POST', 'https://example.test/articles', [
            'Accept' => 'application/vnd.api+json',
            'Content-Type' => 'application/vnd.api+json',
        ]))->withBody((new Psr17Factory())->createStream($body));

        $response = $server->handle($request);

        self::assertSame(200, $response->getStatusCode());

        JsonApiDocument::of($response)
            ->assertHasType('articles')
            ->assertHasAttribute('title', 'A new article')
            ->assertHasAttribute('body', 'Created over HTTP.');
    }

    #[Test]
    public function aMissingResourceRendersA404Error(): void
    {
        $server = $this->server();

        $request = new ServerRequest('GET', 'https://example.test/articles/999', [
            'Accept' => 'application/vnd.api+json',
        ]);

        $response = $server->handle($request);

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * Assembles the `Server` for this API surface: register the schema, supply the
     * PSR-17 factories, install the standard middleware suite plus the router, and
     * set the operation handler that produces responses.
     */
    private function server(): Server
    {
        $psr17 = new Psr17Factory();
        $repository = new ArticleRepository();

        // The base configuration: PSR-17 factories and the registered schema. The
        // error handler renders its error documents through a server, so it is
        // given this base; the returned server adds the middleware and handler.
        $base = Server::make()
            ->withBaseUri('https://example.test')
            ->withPsr17($psr17, $psr17)
            ->register(ArticleResource::class);

        return $base
            ->withMiddleware([
                new ErrorHandlerMiddleware($base),
                new ContentNegotiationMiddleware(),
                new RequestBodyParsingMiddleware(),
                new ArticleRouter(),
            ])
            ->withHandler(new ArticleHandler($repository));
    }
}

/**
 * A plain domain model — no framework or ORM. Public properties keep the example
 * focused on JSON:API rather than on a persistence layer.
 */
final class Article
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public string $body = '',
    ) {}
}

/**
 * A trivial in-memory store standing in for a database or repository.
 */
final class ArticleRepository
{
    /**
     * @var array<int|string, Article>
     */
    private array $articles;

    public function __construct()
    {
        $this->articles = [
            '1' => new Article('1', 'JSON:API in PHP', 'A worked example.'),
            '2' => new Article('2', 'Second article', 'Another one.'),
        ];
    }

    public function find(string $id): ?Article
    {
        return $this->articles[$id] ?? null;
    }

    /**
     * @return list<Article>
     */
    public function all(): array
    {
        return \array_values($this->articles);
    }

    public function save(Article $article): void
    {
        $this->articles[$article->id] = $article;
    }
}

/**
 * The schema: one declaration of the resource type's fields that serves as both
 * the serializer (domain object → resource object) and the hydrator (request →
 * domain object).
 */
final class ArticleResource extends AbstractResource
{
    public static string $type = 'articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(255)->sortable(),
            Str::make('body')->required(),
        ];
    }
}

/**
 * The consumer's operation handler: given a parsed {@see JsonApiOperation},
 * produce a response value object. The PSR-7 plumbing — parsing the request,
 * encoding the response — is handled for you by the adapter the `Server` wraps
 * around this handler.
 */
final class ArticleHandler implements \haddowg\JsonApi\Operation\OperationHandlerInterface
{
    public function __construct(private readonly ArticleRepository $repository) {}

    public function handle(\haddowg\JsonApi\Operation\JsonApiOperationInterface $operation): DataResponse|ErrorResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        $articles = $server->serializerFor('articles');

        return match (true) {
            $operation instanceof FetchResourceOperation => $operation->target()->hasId()
                ? $this->show((string) $operation->target()->id, $server)
                : DataResponse::fromCollection($this->repository->all(), $articles),
            $operation instanceof CreateResourceOperation => $this->create($operation, $server),
            default => ErrorResponse::fromException(new ResourceNotFound()),
        };
    }

    private function show(string $id, Server $server): DataResponse|ErrorResponse
    {
        $article = $this->repository->find($id);
        if ($article === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        return DataResponse::fromResource($article, $server->serializerFor('articles'));
    }

    private function create(CreateResourceOperation $operation, Server $server): DataResponse
    {
        $article = $server->hydratorFor('articles')->hydrate($operation->body(), new Article());
        \assert($article instanceof Article);

        $this->repository->save($article);

        return DataResponse::fromResource($article, $server->serializerFor('articles'));
    }
}

/**
 * A minimal path-prefix router: core does not ship one — mapping a URL to a
 * {@see Target} is the surrounding framework's job. This stand-in recognises
 * `/articles` and `/articles/{id}` and attaches the resolved target as the
 * request attribute the operations adapter reads.
 */
final class ArticleRouter implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = \trim($request->getUri()->getPath(), '/');
        $segments = $path === '' ? [] : \explode('/', $path);

        if (($segments[0] ?? null) === 'articles') {
            $request = $request->withAttribute(
                Target::class,
                new Target('articles', $segments[1] ?? null),
            );
        }

        return $handler->handle($request);
    }
}
