<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Middleware;

use haddowg\JsonApi\Exception\RequestBodyInvalidJson;
use haddowg\JsonApi\Middleware\RequestBodyParsingMiddleware;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Tests\Double\CallableHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RequestBodyParsingMiddlewareTest extends TestCase
{
    #[Test]
    public function wellFormedBodyIsParsedAndReachableDownstream(): void
    {
        $captured = null;
        $handler = new CallableHandler(function (ServerRequestInterface $request) use (&$captured): ResponseInterface {
            $captured = $request;

            return (new Psr17Factory())->createResponse(200);
        });

        $request = (new ServerRequest('POST', '/articles', ['content-type' => 'application/vnd.api+json']))
            ->withBody(Stream::create('{"data":{"type":"articles"}}'));

        (new RequestBodyParsingMiddleware())->process($request, $handler);

        self::assertInstanceOf(JsonApiRequestInterface::class, $captured);
        self::assertSame(['data' => ['type' => 'articles']], $captured->getParsedBody());
    }

    #[Test]
    public function malformedJsonBodyIsRejected(): void
    {
        $handler = new CallableHandler(static fn(): ResponseInterface => (new Psr17Factory())->createResponse(200));

        $request = (new ServerRequest('POST', '/articles', ['content-type' => 'application/vnd.api+json']))
            ->withBody(Stream::create('{not valid json'));

        $this->expectException(RequestBodyInvalidJson::class);

        (new RequestBodyParsingMiddleware())->process($request, $handler);
    }

    #[Test]
    public function emptyBodyPassesThroughUntouched(): void
    {
        $captured = null;
        $handler = new CallableHandler(function (ServerRequestInterface $request) use (&$captured): ResponseInterface {
            $captured = $request;

            return (new Psr17Factory())->createResponse(204);
        });

        $request = new ServerRequest('GET', '/articles');

        $response = (new RequestBodyParsingMiddleware())->process($request, $handler);

        self::assertInstanceOf(JsonApiRequestInterface::class, $captured);
        self::assertNull($captured->getParsedBody());
        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function alreadyWrappedRequestIsNotReWrapped(): void
    {
        $wrapped = new JsonApiRequest(new ServerRequest('GET', '/articles'));

        $captured = null;
        $handler = new CallableHandler(function (ServerRequestInterface $request) use (&$captured): ResponseInterface {
            $captured = $request;

            return (new Psr17Factory())->createResponse(200);
        });

        (new RequestBodyParsingMiddleware())->process($wrapped, $handler);

        self::assertSame($wrapped, $captured);
    }
}
