<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Middleware;

use haddowg\JsonApi\Middleware\JsonApiMiddleware;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Tests\Double\CallableHandler;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The aggregate wires error handling → content negotiation → body parsing in the
 * recommended order behind a single middleware; these assert it reproduces the
 * hand-wired chain's outcomes for the happy path and each rejection path.
 */
final class JsonApiMiddlewareTest extends TestCase
{
    #[Test]
    public function happyPathRunsTheWholeChainAndReachesAWrappedHandler(): void
    {
        $captured = null;
        $handler = new CallableHandler(function (ServerRequestInterface $request) use (&$captured): ResponseInterface {
            $captured = $request;

            return (new Psr17Factory())->createResponse(200);
        });

        $request = (new ServerRequest('POST', '/articles', [
            'content-type' => 'application/vnd.api+json',
            'accept' => 'application/vnd.api+json',
        ]))->withBody(Stream::create('{"data":{"type":"articles"}}'));

        $response = (new JsonApiMiddleware(new StubServer()))->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(JsonApiRequestInterface::class, $captured);
        self::assertSame(['data' => ['type' => 'articles']], $captured->getParsedBody());
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function negotiationRejectionIsCaughtAndRenderedAsAnErrorDocument(): void
    {
        $request = new ServerRequest('GET', '/articles', [
            'content-type' => 'application/vnd.api+json; charset=utf-8',
            'accept' => 'application/vnd.api+json',
        ]);

        $response = (new JsonApiMiddleware(new StubServer()))->process($request, $this->unreachableHandler());

        self::assertSame(415, $response->getStatusCode());
        self::assertStringStartsWith('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    #[Group('spec:errors')]
    public function malformedBodyIsCaughtAndRenderedAsA400(): void
    {
        $request = (new ServerRequest('POST', '/articles', [
            'content-type' => 'application/vnd.api+json',
            'accept' => 'application/vnd.api+json',
        ]))->withBody(Stream::create('{not json'));

        $response = (new JsonApiMiddleware(new StubServer()))->process($request, $this->unreachableHandler());

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    #[Group('spec:errors')]
    public function handlerThrowableIsCaughtAndRenderedAsA500(): void
    {
        $handler = new CallableHandler(static fn(): ResponseInterface => throw new \RuntimeException('boom'));

        $request = new ServerRequest('GET', '/articles', [
            'content-type' => 'application/vnd.api+json',
            'accept' => 'application/vnd.api+json',
        ]);

        $response = (new JsonApiMiddleware(new StubServer()))->process($request, $handler);

        self::assertSame(500, $response->getStatusCode());
    }

    private function unreachableHandler(): CallableHandler
    {
        return new CallableHandler(function (): ResponseInterface {
            self::fail('The inner handler should not be reached when an outer middleware rejects the request.');
        });
    }
}
