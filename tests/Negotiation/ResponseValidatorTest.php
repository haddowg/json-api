<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Negotiation;

use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\ResponseBodyInvalidJson;
use haddowg\JsonApi\Negotiation\ResponseValidator;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ResponseValidator's Content-Type and body well-formedness checks.
 *
 * Rewrites from yin:
 * - DefaultExceptionFactory, JsonSerializer, SerializerInterface constructor args removed;
 *   ResponseValidator is constructed with no arguments.
 * - Laminas\Diactoros\Response replaced with Nyholm\Psr7\Response.
 * - validateJsonApiBody() tests removed entirely — that method is dropped in the Phase-1
 *   trim; JSON-schema body linting is deferred to a later phase.
 * - validateContentTypeHeader() tests added (new behaviour not present in yin's validator).
 * - All tests use PHPUnit 12 attributes (#[Test], #[DataProvider], #[Group]) — no docblocks.
 */
final class ResponseValidatorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // validateContentTypeHeader
    // -------------------------------------------------------------------------

    #[Test]
    #[Group('spec:content-negotiation')]
    public function validateContentTypeHeaderWhenAbsent(): void
    {
        $response = new Response(200);
        $validator = new ResponseValidator();

        $validator->validateContentTypeHeader($response);

        self::addToAssertionCount(1);
    }

    #[Test]
    #[DataProvider('validResponseContentTypes')]
    #[Group('spec:content-negotiation')]
    public function validateContentTypeHeaderWhenValid(string $contentType): void
    {
        $response = (new Response(200))->withHeader('content-type', $contentType);
        $validator = new ResponseValidator();

        $validator->validateContentTypeHeader($response);

        self::addToAssertionCount(1);
    }

    #[Test]
    #[DataProvider('invalidResponseContentTypes')]
    #[Group('spec:content-negotiation')]
    public function validateContentTypeHeaderWhenInvalid(string $contentType): void
    {
        $response = (new Response(200))->withHeader('content-type', $contentType);
        $validator = new ResponseValidator();

        $this->expectException(MediaTypeUnsupported::class);

        $validator->validateContentTypeHeader($response);
    }

    // -------------------------------------------------------------------------
    // validateJsonBody
    // -------------------------------------------------------------------------

    #[Test]
    public function validateJsonBodyWhenEmpty(): void
    {
        $response = new Response(204);
        $validator = new ResponseValidator();

        $validator->validateJsonBody($response);

        self::addToAssertionCount(1);
    }

    #[Test]
    public function validateJsonBodyWhenValid(): void
    {
        $response = new Response(200, [], '{"data": {"type":"abc", "id":"cde"}}');
        $validator = new ResponseValidator();

        $validator->validateJsonBody($response);

        self::addToAssertionCount(1);
    }

    #[Test]
    public function validateJsonBodyWhenInvalid(): void
    {
        $response = new Response(200, [], '{"type');
        $validator = new ResponseValidator();

        $this->expectException(ResponseBodyInvalidJson::class);

        $validator->validateJsonBody($response);
    }

    // -------------------------------------------------------------------------
    // Data providers
    // -------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function validResponseContentTypes(): array
    {
        return [
            'plain json:api'          => ['application/vnd.api+json'],
            'with profile param'      => ['application/vnd.api+json;profile="https://example.com/profiles/last-modified"'],
            'profile uppercase param' => ['application/vnd.api+json; PROFILE="https://example.com/profiles/last-modified"'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function invalidResponseContentTypes(): array
    {
        return [
            'charset param' => ['application/vnd.api+json; charset=utf-8'],
            'ext param'     => ['application/vnd.api+json; ext="ext1,ext2"'],
        ];
    }
}
