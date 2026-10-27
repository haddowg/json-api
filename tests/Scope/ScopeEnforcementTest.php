<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Scope;

use haddowg\JsonApi\Atomic\AtomicLoop;
use haddowg\JsonApi\Atomic\AtomicLoopBackendInterface;
use haddowg\JsonApi\Atomic\AtomicOperationCode;
use haddowg\JsonApi\Atomic\AtomicResult;
use haddowg\JsonApi\Atomic\LocalIdRegistry;
use haddowg\JsonApi\Atomic\OperationDescriptor;
use haddowg\JsonApi\Exception\InsufficientScope;
use haddowg\JsonApi\Middleware\ErrorHandlerMiddleware;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Scope\GrantedScopesProviderInterface;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;
use haddowg\JsonApi\Scope\ScopeEnforcer;
use haddowg\JsonApi\Scope\ScopeTable;
use haddowg\JsonApi\Scope\ScopeTableBuilder;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\AbstractLogger;

/**
 * Scope enforcement through {@see Server}: the operation's own scopes, each `?include`
 * hop, the silent drop of a default include the caller cannot read, and the skips.
 */
#[CoversClass(ScopeEnforcer::class)]
#[CoversClass(InsufficientScope::class)]
#[CoversClass(Server::class)]
final class ScopeEnforcementTest extends TestCase
{
    private ScopeLog $log;

    protected function setUp(): void
    {
        $this->log = new ScopeLog();
    }

    #[Test]
    public function aRequestWithNoGrantIsNotChecked(): void
    {
        self::assertSame(200, $this->get('/sc-articles/1', new Target('sc-articles', '1'), null)->getStatusCode());
    }

    #[Test]
    public function aTokenGrantingTheOperationsScopesPasses(): void
    {
        self::assertSame(200, $this->get('/sc-articles/1', new Target('sc-articles', '1'), 'sc-articles:read')->getStatusCode());
    }

    #[Test]
    public function aTokenMissingAScopeIsRefusedWithAChallenge(): void
    {
        $response = $this->get('/sc-articles/1', new Target('sc-articles', '1'), 'other');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Bearer error="insufficient_scope", scope="sc-articles:read"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertEquals([
            'status' => '403',
            'code' => 'INSUFFICIENT_SCOPE',
            'title' => 'Insufficient scope',
            'detail' => 'The access token does not grant every scope this request needs.',
            'meta' => ['requiredScopes' => ['sc-articles:read'], 'missingScopes' => ['sc-articles:read']],
        ], $this->error($response));
    }

    #[Test]
    public function aWriteNeedsOnlyItsOwnScopes(): void
    {
        $request = (new ServerRequest('DELETE', '/sc-articles/1', ['X-Scopes' => 'sc-articles:read']))
            ->withAttribute(Target::class, new Target('sc-articles', '1'));

        $response = $this->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['requiredScopes' => ['sc-articles:write'], 'missingScopes' => ['sc-articles:write']], $this->error($response)['meta'] ?? null);
    }

    #[Test]
    public function anIncludeHopNeedsTheReadScopesOfTheTypeItReaches(): void
    {
        $response = $this->get('/sc-articles/1?include=author', new Target('sc-articles', '1'), 'sc-articles:read');

        self::assertSame(403, $response->getStatusCode());
        self::assertEquals([
            'status' => '403',
            'code' => 'INSUFFICIENT_SCOPE',
            'title' => 'Insufficient scope',
            'detail' => 'The access token does not grant every scope the include path \'author\' needs.',
            'source' => ['parameter' => 'include'],
            'meta' => ['requiredScopes' => ['sc-people:read'], 'missingScopes' => ['sc-people:read'], 'include' => 'author'],
        ], $this->error($response));
    }

    #[Test]
    public function aToManyHopNeedsTheFetchCollectionScopes(): void
    {
        $response = $this->get('/sc-articles/1?include=comments', new Target('sc-articles', '1'), 'sc-articles:read sc-comments:read');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['sc-comments:list'], self::dig($this->error($response), 'meta', 'requiredScopes'));
    }

    #[Test]
    public function anIntermediateHopIsCheckedToo(): void
    {
        $response = $this->get(
            '/sc-articles/1?include=comments.writer',
            new Target('sc-articles', '1'),
            'sc-articles:read sc-comments:list',
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('comments.writer', self::dig($this->error($response), 'meta', 'include'));
    }

    #[Test]
    public function aGrantedIncludeRenders(): void
    {
        $body = $this->decode($this->get(
            '/sc-articles/1?include=author',
            new Target('sc-articles', '1'),
            'sc-articles:read sc-people:read',
        ));

        self::assertSame(['sc-people'], \array_column($this->list($body['included'] ?? null), 'type'));
    }

    #[Test]
    public function aDefaultIncludeTheCallerCannotReadIsDroppedSilently(): void
    {
        $body = $this->decode($this->get('/sc-articles/1', new Target('sc-articles', '1'), 'sc-articles:read'));

        self::assertArrayNotHasKey('included', $body);
        self::assertSame(
            [['type' => 'sc-comments', 'id' => '7']],
            self::dig($body, 'data', 'relationships', 'comments', 'data'),
        );
    }

    #[Test]
    public function aDefaultIncludeTheCallerCanReadIsKept(): void
    {
        $body = $this->decode($this->get('/sc-articles/1', new Target('sc-articles', '1'), 'sc-articles:read sc-comments:list'));

        self::assertSame(['sc-comments'], \array_column($this->list($body['included'] ?? null), 'type'));
    }

    #[Test]
    public function aDefaultIncludeIsKeptForARequestWithNoGrant(): void
    {
        $body = $this->decode($this->get('/sc-articles/1', new Target('sc-articles', '1'), null));

        self::assertSame(['sc-comments'], \array_column($this->list($body['included'] ?? null), 'type'));
    }

    #[Test]
    public function aHopToATypeTheServerDoesNotDescribeIsLeftToRenderingAndLogged(): void
    {
        $response = $this->get('/sc-articles/1?include=author.ghost', new Target('sc-articles', '1'), 'sc-articles:read sc-people:read');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('the server describes no type "{type}"', $this->log->debug[0] ?? '');
    }

    #[Test]
    public function aRelatedReadNeedsItsOwnAndTheRelatedTypesScopes(): void
    {
        $response = $this->get('/sc-articles/1/author', new Target('sc-articles', '1', 'author'), 'sc-articles:read');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            ['requiredScopes' => ['sc-articles:read', 'sc-people:read'], 'missingScopes' => ['sc-people:read']],
            $this->error($response)['meta'] ?? null,
        );
    }

    #[Test]
    public function dispatchChecksScopesBeforeTheHandler(): void
    {
        $handler = new ScHandler();
        $server = $this->server($handler);
        $request = new JsonApiRequest((new ServerRequest('GET', '/sc-articles/1', ['X-Scopes' => 'other'])));

        try {
            $server->dispatch(new FetchResourceOperation(new Target('sc-articles', '1'), QueryParameters::fromRequest($request), new OperationContext($server, $request)));
            self::fail('The dispatch should be refused.');
        } catch (InsufficientScope $exception) {
            self::assertSame(['sc-articles:read'], $exception->missingScopes);
        }

        self::assertSame(0, $handler->calls);
    }

    #[Test]
    public function aDispatchWithNoHttpRequestIsNotChecked(): void
    {
        $handler = new ScHandler();
        $server = $this->server($handler);

        $server->dispatch(new FetchResourceOperation(new Target('sc-articles', '1'), QueryParameters::fromRequest(new JsonApiRequest(new ServerRequest('GET', '/sc-articles/1'))), new OperationContext($server)));

        self::assertSame(1, $handler->calls);
    }

    #[Test]
    public function aStaleTableIsResolvedLiveWithAWarning(): void
    {
        $handler = new ScHandler();
        $server = $this->server($handler, new ScopeTable(['sc-articles' => []]));
        $request = (new ServerRequest('GET', '/sc-articles/1', ['X-Scopes' => 'other']))
            ->withAttribute(Target::class, new Target('sc-articles', '1'));

        self::assertSame(403, $server->withMiddleware([new ErrorHandlerMiddleware($server)])->handle($request)->getStatusCode());
        self::assertStringContainsString('The scope table has no entry for "{operation}"', $this->log->warning[0] ?? '');
    }

    #[Test]
    public function aBatchIsCheckedEntryByEntry(): void
    {
        $enforcer = $this->enforcer();
        $request = new ServerRequest('POST', '/operations', ['X-Scopes' => 'sc-articles:write']);

        $enforcer->enforceBatch($request, [0 => ['sc-articles', OperationKey::forAtomicOperation(AtomicOperationCode::Add)]]);

        try {
            $enforcer->enforceBatch($request, [
                0 => ['sc-articles', OperationKey::forAtomicOperation(AtomicOperationCode::Update)],
                1 => ['sc-comments', OperationKey::forAtomicOperation(AtomicOperationCode::Remove)],
            ]);
            self::fail('The batch should be refused.');
        } catch (InsufficientScope $exception) {
            self::assertSame(['pointer' => '/atomic:operations/1'], $exception->getErrors()[0]->source?->transform());
        }
    }

    #[Test]
    public function anAtomicRelationshipEntryIsARelationshipMutation(): void
    {
        self::assertSame('addRelationship.tags', OperationKey::forAtomicOperation(AtomicOperationCode::Add, 'tags')->name());
        self::assertSame('updateRelationship.tags', OperationKey::forAtomicOperation(AtomicOperationCode::Update, 'tags')->name());
        self::assertSame('removeRelationship.tags', OperationKey::forAtomicOperation(AtomicOperationCode::Remove, 'tags')->name());
    }

    #[Test]
    public function theAtomicLoopKeepsTheChallengeHeader(): void
    {
        $backend = new class implements AtomicLoopBackendInterface {
            public function begin(): void {}

            public function commit(): void {}

            public function rollback(): void {}

            public function executeOne(OperationDescriptor $op, LocalIdRegistry $lids): AtomicResult
            {
                throw new InsufficientScope(['a', 'b'], ['b']);
            }
        };

        $response = (new AtomicLoop())->run([new OperationDescriptor(AtomicOperationCode::Add, null, null, [], 0)], $backend);

        self::assertInstanceOf(ErrorResponse::class, $response);
        $psr17 = new Psr17Factory();
        $rendered = $response->toPsrResponse(Server::make()->withPsr17($psr17, $psr17), new ServerRequest('POST', '/operations'));
        self::assertSame('Bearer error="insufficient_scope", scope="a b"', $rendered->getHeaderLine('WWW-Authenticate'));
    }

    #[Test]
    public function theGrantIsReadOncePerRequest(): void
    {
        $provider = new ScGrants();
        $enforcer = new ScopeEnforcer($provider, new ScopeTableBuilder(self::metadata(), new ScResolver()));
        $request = new ServerRequest('GET', '/', ['X-Scopes' => 'x']);

        $enforcer->grantedFor($request);
        $enforcer->canInclude($request, 'sc-people', false);

        self::assertSame(1, $provider->calls);
    }

    private function get(string $uri, Target $target, ?string $scopes): ResponseInterface
    {
        $parts = \parse_url($uri);
        \parse_str($parts['query'] ?? '', $query);
        $request = (new ServerRequest('GET', $uri, $scopes === null ? [] : ['X-Scopes' => $scopes]))
            ->withQueryParams($query)
            ->withAttribute(Target::class, $target);

        return $this->handle($request);
    }

    private function handle(ServerRequestInterface $request): ResponseInterface
    {
        $server = $this->server(new ScHandler());

        return $server->withMiddleware([new ErrorHandlerMiddleware($server)])->handle($request);
    }

    private function server(ScHandler $handler, ?ScopeTable $table = null): Server
    {
        $psr17 = new Psr17Factory();

        return Server::make()
            ->withPsr17($psr17, $psr17)
            ->register(ScArticle::class)
            ->register(ScPerson::class)
            ->register(ScComment::class)
            ->withHandler($handler)
            ->withScopeEnforcement($this->enforcer($table));
    }

    private function enforcer(?ScopeTable $table = null): ScopeEnforcer
    {
        return new ScopeEnforcer(new ScGrants(), new ScopeTableBuilder(self::metadata(), new ScResolver()), $table, logger: $this->log);
    }

    private static function metadata(): FakeServerMetadata
    {
        return new FakeServerMetadata(title: 'API', version: '1', types: [
            self::type('sc-articles', [FakeRelationMetadata::toOne('author', ['sc-people']), FakeRelationMetadata::toMany('comments', ['sc-comments'])]),
            self::type('sc-people', [new FakeRelationMetadata('ghost', ['sc-ghosts'], false, relatedEndpoint: false, relationshipEndpoint: false)]),
            self::type('sc-comments', [FakeRelationMetadata::toOne('writer', ['sc-people'])]),
        ]);
    }

    /**
     * @param list<\haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface> $relations
     */
    private static function type(string $type, array $relations): TypeMetadataInterface
    {
        return FakeTypeMetadata::resource(type: $type, fields: [Id::make()->build()], relations: $relations);
    }

    private static function dig(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function error(ResponseInterface $response): array
    {
        $errors = $this->list($this->decode($response)['errors'] ?? null);
        self::assertIsArray($errors[0] ?? null);

        /** @var array<string, mixed> */
        return $errors[0];
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertTrue(\array_is_list($value));

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
    }
}

final class ScopeLog extends AbstractLogger
{
    /** @var list<string> */
    public array $debug = [];

    /** @var list<string> */
    public array $warning = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if ($level === 'debug') {
            $this->debug[] = (string) $message;
        } elseif ($level === 'warning') {
            $this->warning[] = (string) $message;
        }
    }
}

/**
 * Reads are `<type>:read`, a to-many read `<type>:list`, writes `<type>:write`.
 */
final class ScResolver implements OperationScopeResolverInterface
{
    public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array
    {
        if ($type === null) {
            return [];
        }

        return match ($operation->kind->value) {
            'fetchCollection' => [$type->type() . ':list'],
            'fetchOne', 'fetchRelated', 'fetchRelationship' => [$type->type() . ':read'],
            default => [$type->type() . ':write'],
        };
    }
}

/**
 * Grants the space-separated scopes of the `X-Scopes` header; no header, no grant.
 */
final class ScGrants implements GrantedScopesProviderInterface
{
    public int $calls = 0;

    public function granted(ServerRequestInterface $request): ?array
    {
        ++$this->calls;
        if (!$request->hasHeader('X-Scopes')) {
            return null;
        }
        $header = $request->getHeaderLine('X-Scopes');

        return $header === '' ? [] : \explode(' ', $header);
    }
}

final class ScHandler implements OperationHandlerInterface
{
    public int $calls = 0;

    public function handle(JsonApiOperationInterface $operation): DataResponse|RelatedResponse
    {
        ++$this->calls;
        $server = $operation->context()->server;
        $article = (object) [
            'id' => '1',
            'title' => 'Hello',
            'author' => (object) ['id' => '3', 'name' => 'Ann', 'ghost' => null],
            'comments' => [(object) ['id' => '7', 'body' => 'Nice', 'writer' => null]],
        ];

        if ($operation instanceof FetchRelatedOperation) {
            return RelatedResponse::fromResource($article->author, $server->serializerFor('sc-people'));
        }

        return DataResponse::fromResource($article, $server->serializerFor('sc-articles'));
    }
}

final class ScArticle extends AbstractResource
{
    public static string $type = 'sc-articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            BelongsTo::make('author', 'sc-people'),
            HasMany::make('comments', 'sc-comments'),
        ];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return ['comments'];
    }
}

final class ScPerson extends AbstractResource
{
    public static string $type = 'sc-people';

    public function fields(): array
    {
        return [Id::make(), Str::make('name'), BelongsTo::make('ghost', 'sc-ghosts')];
    }
}

final class ScComment extends AbstractResource
{
    public static string $type = 'sc-comments';

    public function fields(): array
    {
        return [Id::make(), Str::make('body'), BelongsTo::make('writer', 'sc-people')];
    }
}
