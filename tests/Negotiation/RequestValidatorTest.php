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
    #[Group('spec:content-negotiation')]
    public function negotiateSkipsTheContentTypeAssertionWhenNotRequired(): void
    {
        // A request body that would normally fail the Content-Type assertion (here a
        // JSON:API media type carrying a disallowed `charset` parameter — the same
        // body a raw-input custom action might send) passes once the assertion is
        // relaxed, while Accept negotiation stays intact.
        $request = $this->createRequest('application/vnd.api+json; charset=utf-8', 'application/vnd.api+json');
        $validator = new RequestValidator();

        $validator->negotiate($request, requireJsonApiContentType: false);

        self::addToAssertionCount(1);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function negotiateStillEnforcesAcceptWhenContentTypeIsNotRequired(): void
    {
        $request = $this->createRequest('application/vnd.api+json; charset=utf-8', 'application/vnd.api+json; charset=utf-8');
        $validator = new RequestValidator();

        $this->expectException(MediaTypeUnacceptable::class);

        $validator->negotiate($request, requireJsonApiContentType: false);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function negotiateStillEnforcesContentTypeByDefault(): void
    {
        $request = $this->createRequest('application/vnd.api+json; charset=utf-8', 'application/vnd.api+json');
        $validator = new RequestValidator();

        $this->expectException(MediaTypeUnsupported::class);

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

    #[Test]
    #[DataProvider('strictlyAcceptedContentTypes')]
    #[Group('spec:content-negotiation')]
    public function strictNegotiationAcceptsTheJsonApiMediaType(string $contentType): void
    {
        (new RequestValidator('https://example.com/ext'))->negotiate($this->createRequest($contentType), strictContentType: true);

        self::addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function strictlyAcceptedContentTypes(): array
    {
        return [
            'bare' => ['application/vnd.api+json'],
            'upper case' => ['Application/VND.API+JSON'],
            'profile' => ['application/vnd.api+json; profile="https://example.com/p"'],
            'supported ext' => ['application/vnd.api+json; ext="https://example.com/ext"'],
        ];
    }

    #[Test]
    #[DataProvider('strictlyRefusedContentTypes')]
    #[Group('spec:content-negotiation')]
    public function strictNegotiationRefusesAnythingElse(string $contentType): void
    {
        $this->expectException(MediaTypeUnsupported::class);

        (new RequestValidator())->negotiate($this->createRequest($contentType), strictContentType: true);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function strictlyRefusedContentTypes(): array
    {
        return [
            'absent' => [''],
            'whitespace' => ['  '],
            'text/plain' => ['text/plain'],
            'form' => ['application/x-www-form-urlencoded'],
            'multipart' => ['multipart/form-data; boundary=x'],
            'plain json' => ['application/json'],
            'charset parameter' => ['application/vnd.api+json; charset=utf-8'],
            'two media types' => ['application/vnd.api+json, text/plain'],
            'unsupported ext' => ['application/vnd.api+json; ext="https://example.com/other"'],
        ];
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function lenientNegotiationStillPassesANonJsonApiContentType(): void
    {
        (new RequestValidator())->negotiate($this->createRequest('text/plain'));
        (new RequestValidator())->negotiate($this->createRequest(''));

        self::addToAssertionCount(2);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function strictModeHasNoEffectWhenTheContentTypeAssertionIsSkipped(): void
    {
        (new RequestValidator())->negotiate($this->createRequest('text/plain'), requireJsonApiContentType: false, strictContentType: true);

        self::addToAssertionCount(1);
    }

    /**
     * @param list<string> $accepted
     */
    #[Test]
    #[DataProvider('matchingActionContentTypes')]
    public function negotiateContentTypeReturnsTheMatch(string $contentType, array $accepted, string $expectedAccepted): void
    {
        $match = (new RequestValidator())->negotiateContentType($this->createRequest($contentType), $accepted);

        self::assertSame($expectedAccepted, $match->accepted);
    }

    /**
     * @return array<string, array{string, list<string>, string}>
     */
    public static function matchingActionContentTypes(): array
    {
        return [
            'exact' => ['text/csv', ['text/csv'], 'text/csv'],
            'parameters ignored' => ['text/csv; charset=utf-8', ['text/csv'], 'text/csv'],
            'type wildcard' => ['image/png', ['image/*'], 'image/*'],
            'any' => ['application/pdf', ['*/*'], '*/*'],
            'safelisted when named' => ['text/plain; charset=utf-8', ['text/plain'], 'text/plain'],
            'form when named' => ['multipart/form-data; boundary=abc', ['multipart/form-data'], 'multipart/form-data'],
            'json:api' => ['application/vnd.api+json', ['application/vnd.api+json', 'image/*'], 'application/vnd.api+json'],
        ];
    }

    /**
     * @param list<string> $accepted
     */
    #[Test]
    #[DataProvider('refusedActionContentTypes')]
    public function negotiateContentTypeRefuses(string $contentType, array $accepted): void
    {
        $this->expectException(MediaTypeUnsupported::class);

        (new RequestValidator())->negotiateContentType($this->createRequest($contentType), $accepted);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function refusedActionContentTypes(): array
    {
        return [
            'absent' => ['', ['*/*']],
            'unlisted' => ['text/csv', ['image/*']],
            'two media types' => ['image/png, image/gif', ['image/*']],
            'text/plain through a wildcard' => ['text/plain', ['text/*']],
            'text/plain through any' => ['text/plain', ['*/*']],
            'form through any' => ['application/x-www-form-urlencoded', ['*/*']],
            'multipart through a wildcard' => ['multipart/form-data; boundary=abc', ['multipart/*']],
            'json:api with a foreign parameter' => ['application/vnd.api+json; charset=utf-8', ['application/vnd.api+json']],
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
