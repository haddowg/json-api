<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Request;

use haddowg\JsonApi\Request\AbstractRequest;
use haddowg\JsonApi\Request\JsonApiRequest;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Tests the PSR-7 delegation layer in AbstractRequest.
 *
 * All wither methods must return a new instance (immutable pattern) while
 * the original is unmodified. JsonApiRequest is constructed from only a PSR-7
 * request.
 */
final class AbstractRequestTest extends TestCase
{
    #[Test]
    public function getProtocolVersion(): void
    {
        $protocolVersion = '2';

        $request = $this->createRequest()->withProtocolVersion($protocolVersion);
        self::assertEquals($protocolVersion, $request->getProtocolVersion());
    }

    #[Test]
    public function getHeaders(): void
    {
        $header1Name = 'a';
        $header1Value = 'b';
        $header2Name = 'c';
        $header2Value = 'd';
        $headers = [$header1Name => [$header1Value], $header2Name => [$header2Value]];

        $request = $this->createRequestWithHeader($header1Name, $header1Value)->withHeader($header2Name, $header2Value);
        self::assertEquals($headers, $request->getHeaders());
    }

    #[Test]
    public function hasHeaderWhenHeaderNotExists(): void
    {
        $request = $this->createRequestWithHeader('a', 'b');

        self::assertFalse($request->hasHeader('c'));
    }

    #[Test]
    public function hasHeaderWhenHeaderExists(): void
    {
        $request = $this->createRequestWithHeader('a', 'b');

        self::assertTrue($request->hasHeader('a'));
    }

    #[Test]
    public function getHeaderWhenHeaderExists(): void
    {
        $request = $this->createRequestWithHeader('a', 'b');

        self::assertEquals(['b'], $request->getHeader('a'));
    }

    #[Test]
    public function getHeaderLineWhenHeaderNotExists(): void
    {
        $request = $this->createRequestWithHeaders(['a' => ['b', 'c', 'd']]);

        self::assertEquals('', $request->getHeaderLine('b'));
    }

    #[Test]
    public function getHeaderLineWhenHeaderExists(): void
    {
        $request = $this->createRequestWithHeaders(['a' => ['b', 'c', 'd']]);

        // PSR-7 allows implementations to join with ", " or "," — Nyholm uses ", "
        self::assertEquals('b, c, d', $request->getHeaderLine('a'));
    }

    #[Test]
    public function withHeader(): void
    {
        $headers = [];
        $headerName = 'a';
        $headerValue = 'b';

        $request = $this->createRequestWithHeaders($headers);
        $newRequest = $request->withHeader($headerName, $headerValue);
        self::assertEquals([], $request->getHeader($headerName));
        self::assertEquals([$headerValue], $newRequest->getHeader($headerName));
    }

    #[Test]
    public function withAddedHeader(): void
    {
        $headerName = 'a';
        $headerValue = 'b';
        $headers = [$headerName => $headerValue];

        $request = $this->createRequestWithHeaders($headers);
        $newRequest = $request->withAddedHeader($headerName, $headerValue);
        self::assertEquals([$headerValue], $request->getHeader($headerName));
        self::assertEquals([$headerValue, $headerValue], $newRequest->getHeader($headerName));
    }

    #[Test]
    public function withoutHeader(): void
    {
        $headerName = 'a';
        $headerValue = 'b';
        $headers = [$headerName => $headerValue];

        $request = $this->createRequestWithHeaders($headers);
        $newRequest = $request->withoutHeader($headerName);

        self::assertEquals([$headerValue], $request->getHeader($headerName));
        self::assertEquals([], $newRequest->getHeader($headerName));
    }

    #[Test]
    public function getBody(): void
    {
        $body = Stream::create('');

        $serverRequest = $this->createMock(ServerRequestInterface::class);
        $serverRequest->expects(self::once())
            ->method('getBody')
            ->willReturn($body);

        $request = $this->createRequest($serverRequest);

        self::assertEquals($body, $request->getBody());
    }

    #[Test]
    public function withBody(): void
    {
        $body = Stream::create('hello');

        $request = $this->createRequest();
        $request = $request->withBody($body);

        self::assertEquals($body, $request->getBody());
    }

    #[Test]
    public function getRequestTarget(): void
    {
        $serverRequest = $this->createMock(ServerRequestInterface::class);
        $serverRequest->expects(self::once())
            ->method('getRequestTarget')
            ->willReturn('/abc');

        $request = $this->createRequest($serverRequest);

        self::assertEquals('/abc', $request->getRequestTarget());
    }

    #[Test]
    public function withRequestTarget(): void
    {
        $request = $this->createRequest();

        $request = $request->withRequestTarget('/abc');

        self::assertEquals('/abc', $request->getRequestTarget());
    }

    #[Test]
    public function getMethod(): void
    {
        $method = 'PUT';

        $request = $this->createRequest();
        $newRequest = $request->withMethod($method);
        self::assertEquals('GET', $request->getMethod());
        self::assertEquals($method, $newRequest->getMethod());
    }

    #[Test]
    public function getUri(): void
    {
        $uri = new Uri();

        $serverRequest = $this->createMock(ServerRequestInterface::class);
        $serverRequest->expects(self::once())
            ->method('getUri')
            ->willReturn($uri);

        $request = $this->createRequest($serverRequest);

        self::assertEquals($uri, $request->getUri());
    }

    #[Test]
    public function withUri(): void
    {
        $request = $this->createRequest();

        $request = $request->withUri(new Uri('https://example.com'));

        self::assertEquals('https://example.com', $request->getUri()->__toString());
    }

    #[Test]
    public function getServerParams(): void
    {
        $serverRequest = $this->createMock(ServerRequestInterface::class);
        $serverRequest->expects(self::once())
            ->method('getServerParams')
            ->willReturn(['abc' => 'def']);

        $request = $this->createRequest($serverRequest);

        self::assertEquals(['abc' => 'def'], $request->getServerParams());
    }

    #[Test]
    public function getCookieParams(): void
    {
        $serverRequest = $this->createMock(ServerRequestInterface::class);
        $serverRequest->expects(self::once())
            ->method('getCookieParams')
            ->willReturn(['abc' => 'def']);

        $request = $this->createRequest($serverRequest);

        self::assertEquals(['abc' => 'def'], $request->getCookieParams());
    }

    #[Test]
    public function withCookieParams(): void
    {
        $request = $this->createRequest();

        $request = $request->withCookieParams(['abc' => 'def']);

        self::assertEquals(['abc' => 'def'], $request->getCookieParams());
    }

    #[Test]
    public function getUploadedFiles(): void
    {
        $serverRequest = $this->createMock(ServerRequestInterface::class);
        $serverRequest->expects(self::once())
            ->method('getUploadedFiles')
            ->willReturn(['abc']);

        $request = $this->createRequest($serverRequest);

        self::assertEquals(['abc'], $request->getUploadedFiles());
    }

    #[Test]
    public function getQueryParams(): void
    {
        $queryParamName = 'abc';
        $queryParamValue = 'cde';
        $queryParams = [$queryParamName => $queryParamValue];

        $request = $this->createRequest();
        $newRequest = $request->withQueryParams($queryParams);
        self::assertEquals([], $request->getQueryParams());
        self::assertEquals($queryParams, $newRequest->getQueryParams());
    }

    #[Test]
    public function getQueryParamWhenNotFound(): void
    {
        $queryParams = [];

        $request = $this->createRequestWithQueryParams($queryParams);
        self::assertEquals('xyz', $request->getQueryParam('a_b', 'xyz'));
    }

    #[Test]
    public function getQueryParamWhenNotEmpty(): void
    {
        $queryParamName = 'abc';
        $queryParamValue = 'cde';
        $queryParams = [$queryParamName => $queryParamValue];

        $request = $this->createRequestWithQueryParams($queryParams);
        self::assertEquals($queryParamValue, $request->getQueryParam($queryParamName));
    }

    #[Test]
    public function withQueryParam(): void
    {
        $queryParams = [];
        $addedQueryParamName = 'abc';
        $addedQueryParamValue = 'def';

        $request = $this->createRequestWithQueryParams($queryParams);
        $newRequest = $request->withQueryParam($addedQueryParamName, $addedQueryParamValue);
        self::assertNull($request->getQueryParam($addedQueryParamName));
        self::assertEquals($addedQueryParamValue, $newRequest->getQueryParam($addedQueryParamName));
    }

    #[Test]
    public function getParsedBody(): void
    {
        $parsedBody = [
            'data' => [
                'type' => 'cat',
                'id' => 'tom',
            ],
        ];

        $request = $this->createRequest();
        $newRequest = $request->withParsedBody($parsedBody);
        self::assertNull($request->getParsedBody());
        self::assertEquals($parsedBody, $newRequest->getParsedBody());
    }

    #[Test]
    public function getAttributes(): void
    {
        $attribute1Key = 'a';
        $attribute1Value = true;
        $attribute2Key = 'b';
        $attribute2Value = 123456;
        $attributes = [$attribute1Key => $attribute1Value, $attribute2Key => $attribute2Value];

        $request = $this->createRequest();
        $newRequest = $request
            ->withAttribute($attribute1Key, $attribute1Value)
            ->withAttribute($attribute2Key, $attribute2Value);

        self::assertEquals([], $request->getAttributes());
        self::assertEquals($attributes, $newRequest->getAttributes());
        self::assertEquals($attribute1Value, $newRequest->getAttribute($attribute1Key));
    }

    #[Test]
    public function withoutAttributes(): void
    {
        $request = $this->createRequest();
        $newRequest = $request
            ->withAttribute('abc', 'cde')
            ->withoutAttribute('abc');

        self::assertEquals([], $request->getAttributes());
        self::assertEmpty($newRequest->getAttributes());
    }

    private function createRequest(?ServerRequestInterface $serverRequest = null): JsonApiRequest
    {
        return new JsonApiRequest($serverRequest ?? new ServerRequest('GET', '/'));
    }

    /** @param array<string, mixed> $headers */
    private function createRequestWithHeaders(array $headers): AbstractRequest
    {
        $psrRequest = new ServerRequest('GET', '/', $headers);

        return new JsonApiRequest($psrRequest);
    }

    private function createRequestWithHeader(string $headerName, string $headerValue): AbstractRequest
    {
        $psrRequest = new ServerRequest('GET', '/', [$headerName => $headerValue]);

        return new JsonApiRequest($psrRequest);
    }

    /** @param array<string, mixed> $queryParams */
    private function createRequestWithQueryParams(array $queryParams): AbstractRequest
    {
        $psrRequest = (new ServerRequest('GET', '/'))->withQueryParams($queryParams);

        return new JsonApiRequest($psrRequest);
    }
}
