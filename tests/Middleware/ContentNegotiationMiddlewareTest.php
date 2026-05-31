<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Middleware;

use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApi\Middleware\ContentNegotiationMiddleware;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Tests\Double\CallableHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ContentNegotiationMiddlewareTest extends TestCase
{
    private const string EXT = 'https://example.com/ext/foo';

    #[Test]
    #[Group('spec:content-negotiation')]
    public function validRequestPassesThroughWrapped(): void
    {
        $captured = null;
        $handler = new CallableHandler(function (ServerRequestInterface $request) use (&$captured): ResponseInterface {
            $captured = $request;

            return (new Psr17Factory())->createResponse(200);
        });

        $request = new ServerRequest('GET', '/articles', [
            'content-type' => 'application/vnd.api+json',
            'accept' => 'application/vnd.api+json',
        ]);

        $response = (new ContentNegotiationMiddleware())->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(JsonApiRequestInterface::class, $captured);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function unsupportedContentTypeMediaTypeParameterIsRejected(): void
    {
        $request = new ServerRequest('GET', '/articles', [
            'content-type' => 'application/vnd.api+json; charset=utf-8',
            'accept' => 'application/vnd.api+json',
        ]);

        $this->expectException(MediaTypeUnsupported::class);

        (new ContentNegotiationMiddleware())->process($request, $this->okHandler());
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function unacceptableAcceptMediaTypeParameterIsRejected(): void
    {
        $request = new ServerRequest('GET', '/articles', [
            'content-type' => 'application/vnd.api+json',
            'accept' => 'application/vnd.api+json; charset=utf-8',
        ]);

        $this->expectException(MediaTypeUnacceptable::class);

        (new ContentNegotiationMiddleware())->process($request, $this->okHandler());
    }

    #[Test]
    #[Group('spec:extensions-and-profiles')]
    public function unsupportedExtensionOnContentTypeYields415(): void
    {
        $request = new ServerRequest('GET', '/articles', [
            'content-type' => 'application/vnd.api+json; ext="' . self::EXT . '"',
            'accept' => 'application/vnd.api+json',
        ]);

        $this->expectException(MediaTypeUnsupported::class);

        (new ContentNegotiationMiddleware())->process($request, $this->okHandler());
    }

    #[Test]
    #[Group('spec:extensions-and-profiles')]
    public function unsupportedExtensionOnAcceptYields406(): void
    {
        $request = new ServerRequest('GET', '/articles', [
            'content-type' => 'application/vnd.api+json',
            'accept' => 'application/vnd.api+json; ext="' . self::EXT . '"',
        ]);

        $this->expectException(MediaTypeUnacceptable::class);

        (new ContentNegotiationMiddleware())->process($request, $this->okHandler());
    }

    #[Test]
    #[Group('spec:extensions-and-profiles')]
    public function supportedExtensionIsAccepted(): void
    {
        $request = new ServerRequest('GET', '/articles', [
            'content-type' => 'application/vnd.api+json; ext="' . self::EXT . '"',
            'accept' => 'application/vnd.api+json',
        ]);

        $response = (new ContentNegotiationMiddleware(self::EXT))->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    #[Group('spec:extensions-and-profiles')]
    public function unrecognizedProfileIsIgnoredNotRejected(): void
    {
        $request = (new ServerRequest('GET', '/articles', [
            'content-type' => 'application/vnd.api+json',
            'accept' => 'application/vnd.api+json; profile="https://example.com/profiles/unknown"',
        ]))->withQueryParams(['profile' => 'https://example.com/profiles/unknown']);

        $response = (new ContentNegotiationMiddleware())->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    #[Group('spec:query-parameters')]
    public function unrecognizedQueryParameterIsRejected(): void
    {
        $request = (new ServerRequest('GET', '/articles', [
            'content-type' => 'application/vnd.api+json',
            'accept' => 'application/vnd.api+json',
        ]))->withQueryParams(['unknown' => 'x']);

        $this->expectException(QueryParamUnrecognized::class);

        (new ContentNegotiationMiddleware())->process($request, $this->okHandler());
    }

    private function okHandler(): CallableHandler
    {
        return new CallableHandler(static fn(): ResponseInterface => (new Psr17Factory())->createResponse(200));
    }
}
