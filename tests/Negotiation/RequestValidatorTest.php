<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Negotiation;

use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApi\Exception\RequestBodyInvalidJson;
use haddowg\JsonApi\Negotiation\RequestValidator;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RequestValidator's content-negotiation and query-param validation.
 *
 * RequestValidator is constructed with no arguments and JsonApiRequest from only
 * a PSR-7 ServerRequest. Requests are built as real JsonApiRequest instances with
 * Nyholm\Psr7. validateJsonBody() is a delegating trigger; tests verify it passes
 * for empty/valid bodies and throws RequestBodyInvalidJson for malformed JSON via
 * the raw-body path.
 */
final class RequestValidatorTest extends TestCase
{
    #[Test]
    #[Group('spec:content-negotiation')]
    public function negotiateWhenValidRequest(): void
    {
        $request = $this->createRequest('application/vnd.api+json', 'application/vnd.api+json');
        $validator = new RequestValidator();

        $validator->negotiate($request);

        self::addToAssertionCount(1);
    }

    #[Test]
    #[DataProvider('validContentTypes')]
    #[Group('spec:content-negotiation')]
    public function negotiateWhenContentTypeHeaderSupported(string $contentType): void
    {
        $request = $this->createRequest($contentType, 'application/vnd.api+json');
        $validator = new RequestValidator();

        $validator->negotiate($request);

        self::addToAssertionCount(1);
    }

    #[Test]
    #[DataProvider('invalidContentTypes')]
    #[Group('spec:content-negotiation')]
    public function negotiateWhenContentTypeHeaderUnsupported(string $contentType): void
    {
        $request = $this->createRequest($contentType, 'application/vnd.api+json');
        $validator = new RequestValidator();

        $this->expectException(MediaTypeUnsupported::class);

        $validator->negotiate($request);
    }

    #[Test]
    #[DataProvider('validContentTypes')]
    #[Group('spec:content-negotiation')]
    public function negotiateWhenAcceptHeaderAcceptable(string $accept): void
    {
        $request = $this->createRequest('application/vnd.api+json', $accept);
        $validator = new RequestValidator();

        $validator->negotiate($request);

        self::addToAssertionCount(1);
    }

    #[Test]
    #[DataProvider('invalidContentTypes')]
    #[Group('spec:content-negotiation')]
    public function negotiateWhenAcceptHeaderUnacceptable(string $accept): void
    {
        $request = $this->createRequest('application/vnd.api+json', $accept);
        $validator = new RequestValidator();

        $this->expectException(MediaTypeUnacceptable::class);

        $validator->negotiate($request);
    }

    #[Test]
    #[Group('spec:query-parameters')]
    public function validateQueryParamsWhenValid(): void
    {
        $psrRequest = (new ServerRequest('GET', '/'))->withQueryParams([
            'fields'  => ['foo' => 'bar'],
            'include' => 'baz',
            'sort'    => 'asc',
            'page'    => '1',
            'filter'  => 'search',
            'profile' => 'https://example.com/profiles/last-modified',
        ]);
        $request = new JsonApiRequest($psrRequest);
        $validator = new RequestValidator();

        $validator->validateQueryParams($request);

        self::addToAssertionCount(1);
    }

    #[Test]
    #[Group('spec:query-parameters')]
    public function validateQueryParamsWhenInvalid(): void
    {
        $psrRequest = (new ServerRequest('GET', '/'))->withQueryParams(['foo' => 'bar']);
        $request = new JsonApiRequest($psrRequest);
        $validator = new RequestValidator();

        $this->expectException(QueryParamUnrecognized::class);
        $this->expectExceptionMessage("Query parameter 'foo' can't be recognized!");

        $validator->validateQueryParams($request);
    }

    #[Test]
    public function validateJsonBodyWhenEmpty(): void
    {
        $request = $this->createRequestWithRawBody('');
        $validator = new RequestValidator();

        $validator->validateJsonBody($request);

        self::addToAssertionCount(1);
    }

    #[Test]
    #[DataProvider('validJsonBodies')]
    public function validateJsonBodyWhenValid(string $body): void
    {
        $request = $this->createRequestWithRawBody($body);
        $validator = new RequestValidator();

        $validator->validateJsonBody($request);

        self::addToAssertionCount(1);
    }

    #[Test]
    #[DataProvider('invalidJsonBodies')]
    public function validateJsonBodyWhenInvalid(string $body): void
    {
        $request = $this->createRequestWithRawBody($body);
        $validator = new RequestValidator();

        $this->expectException(RequestBodyInvalidJson::class);

        $validator->validateJsonBody($request);
    }

    // -------------------------------------------------------------------------
    // Data providers
    // -------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function invalidContentTypes(): array
    {
        return [
            'charset param'    => ['application/vnd.api+json; charset=utf-8'],
            'ext param'        => ['application/vnd.api+json; ext="ext1,ext2"'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function validContentTypes(): array
    {
        return [
            'plain json:api'              => ['application/vnd.api+json'],
            'with profile param'          => ['application/vnd.api+json;profile="https://example.com/profiles/last-modified"'],
            'profile + plain'             => ['application/vnd.api+json;profile="https://example.com/profiles/last-modified", application/vnd.api+json'],
            'profile uppercase param'     => ['application/vnd.api+json; PROFILE="https://example.com/profiles/last-modified", application/vnd.api+json'],
            'text/html'                   => ['text/html; charset=utf-8'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function validJsonBodies(): array
    {
        return [
            'empty object'     => ['{}'],
            'nested object'    => ['{"employees":[{"firstName":"John","lastName":"Doe"}]}'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function invalidJsonBodies(): array
    {
        return [
            'truncated'   => ['{abc'],
            'bom prefix'  => ["{\xEF\xBB\xBF}"],
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createRequest(string $contentType, string $accept = ''): JsonApiRequestInterface
    {
        $headers = ['content-type' => $contentType];
        if ($accept !== '') {
            $headers['accept'] = $accept;
        }

        return new JsonApiRequest(new ServerRequest('GET', '/', $headers));
    }

    private function createRequestWithRawBody(string $body): JsonApiRequestInterface
    {
        $stream = Stream::create($body);
        $psrRequest = (new ServerRequest('GET', '/'))->withBody($stream);

        return new JsonApiRequest($psrRequest);
    }
}
