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
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[Test]
    #[DataProvider('writesWithoutAJsonApiContentType')]
    #[Group('spec:content-negotiation')]
    public function aWriteWithoutAJsonApiContentTypeIsRejected(string $method, string $contentType, string $body): void
    {
        $headers = ['accept' => 'application/vnd.api+json'];
        if ($contentType !== '') {
            $headers['content-type'] = $contentType;
        }

        $this->expectException(MediaTypeUnsupported::class);

        (new ContentNegotiationMiddleware())->process(new ServerRequest($method, '/articles', $headers, $body), $this->okHandler());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function writesWithoutAJsonApiContentType(): array
    {
        $document = '{"data":{"type":"articles"}}';

        return [
            'post text/plain' => ['POST', 'text/plain', $document],
            'post form' => ['POST', 'application/x-www-form-urlencoded', 'data=1'],
            'post absent' => ['POST', '', $document],
            'post absent without a body' => ['POST', '', ''],
            'patch json' => ['PATCH', 'application/json', $document],
            'patch absent' => ['PATCH', '', $document],
            'put text/plain' => ['PUT', 'text/plain', $document],
            'relationship delete text/plain' => ['DELETE', 'text/plain', '{"data":[]}'],
        ];
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function aFormParsedBodyCountsAsABodyOnDelete(): void
    {
        $request = (new ServerRequest('DELETE', '/articles/1/relationships/tags', ['content-type' => 'application/x-www-form-urlencoded']))
            ->withParsedBody(['data' => '1']);

        $this->expectException(MediaTypeUnsupported::class);

        (new ContentNegotiationMiddleware())->process($request, $this->okHandler());
    }

    #[Test]
    #[DataProvider('requestsWithoutADocument')]
    #[Group('spec:content-negotiation')]
    public function aRequestWithoutADocumentNeedsNoContentType(string $method): void
    {
        $response = (new ContentNegotiationMiddleware())->process(
            new ServerRequest($method, '/articles/1', ['accept' => 'application/vnd.api+json']),
            $this->okHandler(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requestsWithoutADocument(): array
    {
        return [
            'get' => ['GET'],
            'head' => ['HEAD'],
            'options' => ['OPTIONS'],
            'resource delete' => ['DELETE'],
            'bodyless patch' => ['PATCH'],
            'bodyless put' => ['PUT'],
        ];
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function aWriteWithTheJsonApiContentTypePasses(): void
    {
        $response = (new ContentNegotiationMiddleware())->process(
            new ServerRequest('POST', '/articles', ['content-type' => 'application/vnd.api+json', 'accept' => 'application/vnd.api+json'], '{"data":{"type":"articles"}}'),
            $this->okHandler(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    private function okHandler(): CallableHandler
    {
        return new CallableHandler(static fn(): ResponseInterface => (new Psr17Factory())->createResponse(200));
    }
}
