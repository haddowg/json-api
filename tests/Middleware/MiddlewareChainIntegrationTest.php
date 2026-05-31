<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Middleware;

use haddowg\JsonApi\Middleware\ContentNegotiationMiddleware;
use haddowg\JsonApi\Middleware\ErrorHandlerMiddleware;
use haddowg\JsonApi\Middleware\Internal\MiddlewareHandler;
use haddowg\JsonApi\Middleware\RequestBodyParsingMiddleware;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\Psr7ToOperationHandlerAdapter;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Tests\Double\CallableHandler;
use haddowg\JsonApi\Tests\Double\RecordingOperationHandler;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * End-to-end exercise of the standard middleware chain (error handler → content
 * negotiation → body parsing → handler) assembled by hand over a {@see StubServer}
 * and a tiny inline PSR-15 dispatcher — the shape the Phase 4.5 `Server` will use
 * internally.
 */
final class MiddlewareChainIntegrationTest extends TestCase
{
    #[Test]
    #[Group('spec:fetching-data')]
    public function happyPathRendersAnOperationHandlerDataResponseToPsr7(): void
    {
        $operationHandler = new RecordingOperationHandler(
            DataResponse::fromResource(new \stdClass(), new StubResource('articles', '1')),
        );
        $server = new StubServer();
        $adapter = new Psr7ToOperationHandlerAdapter($operationHandler, $server);

        $request = (new ServerRequest('GET', '/articles/1', [
            'content-type' => 'application/vnd.api+json',
            'accept' => 'application/vnd.api+json',
        ]))->withAttribute(Target::class, new Target('articles', '1'));

        $response = $this->chain($server, $adapter)->handle($request);

        self::assertInstanceOf(FetchResourceOperation::class, $operationHandler->received);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function aBarePsr7ResponseFromTheHandlerPassesThroughUnchanged(): void
    {
        $server = new StubServer();
        $expected = (new Psr17Factory())->createResponse(204);
        $handler = new CallableHandler(static fn(): ResponseInterface => $expected);

        $request = new ServerRequest('GET', '/articles', [
            'content-type' => 'application/vnd.api+json',
            'accept' => 'application/vnd.api+json',
        ]);

        $response = $this->chain($server, $handler)->handle($request);

        self::assertSame($expected, $response);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function wrongContentTypeYields415(): void
    {
        $server = new StubServer();
        $request = new ServerRequest('GET', '/articles', [
            'content-type' => 'application/vnd.api+json; charset=utf-8',
            'accept' => 'application/vnd.api+json',
        ]);

        $response = $this->chain($server)->handle($request);

        self::assertSame(415, $response->getStatusCode());
        self::assertStringStartsWith('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    #[Group('spec:extensions-and-profiles')]
    public function unsupportedAcceptExtensionYields406(): void
    {
        $server = new StubServer();
        $request = new ServerRequest('GET', '/articles', [
            'content-type' => 'application/vnd.api+json',
            'accept' => 'application/vnd.api+json; ext="https://example.com/ext/foo"',
        ]);

        $response = $this->chain($server)->handle($request);

        self::assertSame(406, $response->getStatusCode());
    }

    #[Test]
    #[Group('spec:errors')]
    public function malformedJsonBodyYields400(): void
    {
        $server = new StubServer();
        $request = (new ServerRequest('POST', '/articles', [
            'content-type' => 'application/vnd.api+json',
            'accept' => 'application/vnd.api+json',
        ]))->withBody(Stream::create('{not json'));

        $response = $this->chain($server)->handle($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    #[Group('spec:errors')]
    public function unexpectedThrowableYields500WithRedactedBody(): void
    {
        $server = new StubServer();
        $handler = new CallableHandler(static fn(): ResponseInterface => throw new \RuntimeException('leaky secret'));

        $request = new ServerRequest('GET', '/articles', [
            'content-type' => 'application/vnd.api+json',
            'accept' => 'application/vnd.api+json',
        ]);

        $response = $this->chain($server, $handler)->handle($request);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('leaky secret', (string) $response->getBody());
    }

    #[Test]
    public function twoChainsOwnTheirOwnMiddlewareLists(): void
    {
        // The bad media-type parameter would be rejected by negotiation but is
        // harmless to a chain that does not negotiate — proving each chain runs
        // its own middleware list and that selection is the caller's concern.
        $request = new ServerRequest('GET', '/articles', [
            'content-type' => 'application/vnd.api+json; charset=utf-8',
            'accept' => 'application/vnd.api+json',
        ]);
        $ok = new CallableHandler(static fn(): ResponseInterface => (new Psr17Factory())->createResponse(200));

        $negotiating = $this->pipeline(
            [new ErrorHandlerMiddleware(new StubServer()), new ContentNegotiationMiddleware()],
            $ok,
        );
        $lenient = $this->pipeline(
            [new ErrorHandlerMiddleware(new StubServer())],
            $ok,
        );

        self::assertSame(415, $negotiating->handle($request)->getStatusCode());
        self::assertSame(200, $lenient->handle($request)->getStatusCode());
    }

    /**
     * The standard chain: error handler (outermost) → content negotiation → body
     * parsing → the given handler (a no-op 200 handler by default).
     */
    private function chain(StubServer $server, ?RequestHandlerInterface $handler = null): RequestHandlerInterface
    {
        $handler ??= new CallableHandler(static fn(): ResponseInterface => (new Psr17Factory())->createResponse(200));

        return $this->pipeline(
            [
                new ErrorHandlerMiddleware($server),
                new ContentNegotiationMiddleware(),
                new RequestBodyParsingMiddleware(),
            ],
            $handler,
        );
    }

    /**
     * Folds a middleware list (outermost first) around a final handler into one
     * PSR-15 request handler.
     *
     * @param list<MiddlewareInterface> $middleware
     */
    private function pipeline(array $middleware, RequestHandlerInterface $handler): RequestHandlerInterface
    {
        foreach (\array_reverse($middleware) as $mw) {
            $handler = new MiddlewareHandler($mw, $handler);
        }

        return $handler;
    }
}
