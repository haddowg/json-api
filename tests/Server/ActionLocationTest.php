<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\OpenApi\Metadata\ActionResponse;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\OpenApi\SpecDescribedByResolver;
use haddowg\JsonApi\Operation\CustomActionOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\VendoredSchemaProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * A {@see SeeOtherResponse} a custom action returns, dispatched through
 * {@see Server::dispatch()}: the location document for a request preferring JSON:API,
 * the `303` for any other, never cached.
 */
#[CoversClass(SeeOtherResponse::class)]
#[CoversClass(Server::class)]
final class ActionLocationTest extends TestCase
{
    private const HOST = 'https://api.example.com';

    private const URL = 'https://storage.example.com/files/1.csv?signature=abc';

    private const JSON_API = 'application/vnd.api+json';

    #[Test]
    public function aJsonApiRequestGetsTheLocationDocument(): void
    {
        $expiresAt = new \DateTimeImmutable('2026-10-27T12:00:00+00:00');
        $response = $this->action(SeeOtherResponse::to(self::URL, $expiresAt), self::JSON_API, [new SeeOther(mediaType: 'text/csv')]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::JSON_API, $response->getHeaderLine('Content-Type'));
        self::assertFalse($response->hasHeader('Location'));
        self::assertSame([
            'links' => [
                'self' => self::HOST . '/files/1/-actions/download',
                'location' => [
                    'href' => self::URL,
                    'type' => 'text/csv',
                    'meta' => ['expiresAt' => '2026-10-27T12:00:00+00:00'],
                ],
            ],
            'meta' => ['expiresAt' => '2026-10-27T12:00:00+00:00'],
            'jsonapi' => ['version' => '1.1'],
        ], $this->decode($response));
    }

    #[Test]
    public function theLocationDocumentIsValidJsonApi(): void
    {
        $response = $this->action(SeeOtherResponse::to(self::URL), self::JSON_API);

        (new DocumentValidator(new VendoredSchemaProvider()))->validateResponse($this->decode($response));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function theSelfLinkIsTheActionUrlAndDescribedLikeAnyBuiltLink(): void
    {
        $server = $this->server()->withLinkObjects()->withDescribedByResolver(new SpecDescribedByResolver('/docs/openapi.json', ['/files/{id}/-actions/download' => ['get']]));
        $response = $this->action(SeeOtherResponse::to(self::URL), self::JSON_API, server: $server);

        $links = $this->decode($response)['links'] ?? null;
        self::assertIsArray($links);
        self::assertSame([
            'href' => self::HOST . '/files/1/-actions/download',
            'describedby' => '/docs/openapi.json#/paths/~1files~1%7Bid%7D~1-actions~1download/get',
        ], $links['self']);
        // The location link is the URL the action produced, never described by an operation.
        self::assertSame(['href' => self::URL], $links['location']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function redirectingAccepts(): iterable
    {
        yield 'absent' => [''];
        yield 'any' => ['*/*'];
        yield 'browser navigation' => ['text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,*/*;q=0.8'];
        yield 'the target media type' => ['text/csv'];
        yield 'JSON:API outweighed' => ['application/vnd.api+json;q=0.5, text/html'];
        yield 'JSON:API outweighed by a wildcard' => ['application/vnd.api+json;q=0.8, */*'];
        yield 'JSON:API with a forbidden parameter only' => ['application/vnd.api+json; charset=utf-8'];
        yield 'JSON:API refused' => ['application/vnd.api+json;q=0, */*'];
    }

    #[Test]
    #[DataProvider('redirectingAccepts')]
    public function anyOtherRequestGetsThe303(string $accept): void
    {
        $response = $this->action(SeeOtherResponse::to(self::URL, new \DateTimeImmutable('2026-10-27T12:00:00Z')), $accept, [new SeeOther(mediaType: 'text/csv')]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(self::URL, $response->getHeaderLine('Location'));
        self::assertSame('', (string) $response->getBody());
        self::assertFalse($response->hasHeader('Content-Type'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documentAccepts(): iterable
    {
        yield 'JSON:API alone' => ['application/vnd.api+json'];
        yield 'JSON:API beside a wildcard' => ['application/vnd.api+json, */*'];
        yield 'JSON:API tied with another type' => ['text/html, application/vnd.api+json'];
        yield 'JSON:API tied with a weighted wildcard' => ['application/vnd.api+json;q=0.8, */*;q=0.8'];
        yield 'one conforming JSON:API instance' => ['application/vnd.api+json; charset=utf-8, application/vnd.api+json'];
        yield 'JSON:API with a profile' => ['application/vnd.api+json; profile="https://example.com/profile"'];
    }

    #[Test]
    #[DataProvider('documentAccepts')]
    public function aRequestNamingJsonApiFirstGetsTheDocument(string $accept): void
    {
        self::assertSame(200, $this->action(SeeOtherResponse::to(self::URL), $accept)->getStatusCode());
    }

    #[Test]
    public function bothShapesAreNeverCachedAndVaryOnAccept(): void
    {
        foreach ([self::JSON_API, 'text/html'] as $accept) {
            $response = $this->action(SeeOtherResponse::to(self::URL), $accept);

            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'), $accept);
            self::assertSame('Accept', $response->getHeaderLine('Vary'), $accept);
        }
    }

    #[Test]
    public function aHandlersOwnCacheControlWins(): void
    {
        foreach ([self::JSON_API, 'text/html'] as $accept) {
            $response = $this->action(SeeOtherResponse::to(self::URL)->withHeader('cache-control', 'private, max-age=60'), $accept);

            self::assertSame('private, max-age=60', $response->getHeaderLine('Cache-Control'), $accept);
        }
    }

    #[Test]
    public function withoutAnExpiryTheLinkHasNoMetaAndTheTopLevelExpiryIsNull(): void
    {
        $document = $this->decode($this->action(SeeOtherResponse::to(self::URL), self::JSON_API));

        self::assertSame(['expiresAt' => null], $document['meta']);
        self::assertSame(['href' => self::URL], $this->location($document));
    }

    #[Test]
    public function theExpiryIsWrittenInTheServersOutputTimezone(): void
    {
        $server = $this->server()->withOutputTimezone('America/New_York');
        $expiresAt = new \DateTimeImmutable('2026-10-27T12:00:00+00:00');
        $document = $this->decode($this->action(SeeOtherResponse::to(self::URL, $expiresAt), self::JSON_API, server: $server));

        self::assertSame(['expiresAt' => '2026-10-27T08:00:00-04:00'], $document['meta']);
        self::assertSame(['href' => self::URL, 'meta' => ['expiresAt' => '2026-10-27T08:00:00-04:00']], $this->location($document));
    }

    #[Test]
    public function withoutAnOutputTimezoneTheExpiryKeepsItsOwnZone(): void
    {
        $expiresAt = new \DateTimeImmutable('2026-10-27T13:00:00+01:00');
        $document = $this->decode($this->action(SeeOtherResponse::to(self::URL, $expiresAt), self::JSON_API));

        self::assertSame(['expiresAt' => '2026-10-27T13:00:00+01:00'], $document['meta']);
    }

    #[Test]
    public function theTypeIsTheDeclaredMediaTypeUnlessTheHandlerSetsOne(): void
    {
        $declared = [new SeeOther(mediaType: 'text/csv')];

        $untyped = $this->decode($this->action(SeeOtherResponse::to(self::URL), self::JSON_API));
        $typed = $this->decode($this->action(SeeOtherResponse::to(self::URL), self::JSON_API, $declared));
        $handlerTyped = $this->decode($this->action(SeeOtherResponse::to(self::URL)->withType('application/pdf'), self::JSON_API, $declared));

        self::assertArrayNotHasKey('type', $this->location($untyped));
        self::assertSame('text/csv', $this->location($typed)['type'] ?? null);
        self::assertSame('application/pdf', $this->location($handlerTyped)['type'] ?? null);
    }

    #[Test]
    public function aHandlersMetaJoinsTheExpiry(): void
    {
        $document = $this->decode($this->action(SeeOtherResponse::to(self::URL)->withMeta(['filename' => 'report.csv']), self::JSON_API));

        self::assertSame(['expiresAt' => null, 'filename' => 'report.csv'], $document['meta']);
    }

    #[Test]
    public function aFetchsSeeOtherIsAlwaysTheBareRedirect(): void
    {
        $server = $this->server();
        $request = $this->request('/jobs/1', self::JSON_API);
        $operation = new FetchResourceOperation(new Target('jobs', '1'), QueryParameters::fromRequest($request), new OperationContext($server, $request));

        $response = $server->withHandler(new LocationHandler(SeeOtherResponse::to('/articles/1')))
            ->dispatch($operation)
            ->toPsrResponse($server, $request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/articles/1', $response->getHeaderLine('Location'));
        self::assertFalse($response->hasHeader('Cache-Control'));
        self::assertFalse($response->hasHeader('Vary'));
    }

    #[Test]
    public function aSeeOtherRenderedOutsideAnActionIsTheBareRedirect(): void
    {
        $response = SeeOtherResponse::to(self::URL)->toPsrResponse($this->server(), $this->request('/files/1/-actions/download', self::JSON_API));

        self::assertSame(303, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Cache-Control'));
    }

    /**
     * @param list<ActionResponse> $responds
     */
    private function action(SeeOtherResponse $returned, string $accept, array $responds = [], ?Server $server = null): ResponseInterface
    {
        $server ??= $this->server();
        $request = $this->request('/files/1/-actions/download', $accept);
        $operation = new CustomActionOperation(
            new Target('files', '1'),
            QueryParameters::fromRequest($request),
            new OperationContext($server, $request),
            'download',
            'GET',
            responds: $responds,
        );

        return $server->withHandler(new LocationHandler($returned))->dispatch($operation)->toPsrResponse($server, $request);
    }

    private function request(string $path, string $accept): JsonApiRequest
    {
        $request = new ServerRequest('GET', self::HOST . $path);
        if ($accept !== '') {
            $request = $request->withHeader('Accept', $accept);
        }

        return new JsonApiRequest($request);
    }

    private function server(): Server
    {
        $psr17 = new Psr17Factory();

        return Server::make()->withPsr17($psr17, $psr17)->withLinkObjects(false);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<mixed>
     */
    private function location(array $document): array
    {
        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        $location = $links['location'] ?? null;
        self::assertIsArray($location);

        return $location;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = \json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}

final readonly class LocationHandler implements OperationHandlerInterface
{
    public function __construct(private SeeOtherResponse $response) {}

    public function handle(JsonApiOperationInterface $operation): SeeOtherResponse
    {
        return $this->response;
    }
}
