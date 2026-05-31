<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Negotiation;

use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\ResponseBodyInvalidJson;
use haddowg\JsonApi\Request\MediaType;
use Psr\Http\Message\ResponseInterface;

/**
 * Validates JSON:API content-negotiation constraints on an outgoing response.
 *
 * Only Content-Type header validation and JSON well-formedness checking are
 * performed. JSON-schema body linting is not performed here.
 *
 * Exceptions are thrown directly as typed instances.
 */
final class ResponseValidator
{
    /**
     * Validates the Content-Type header of the response against the JSON:API
     * media type rules.
     *
     * Responses MUST use application/vnd.api+json; only the "profile" parameter
     * is allowed alongside it.
     *
     * @throws MediaTypeUnsupported
     */
    public function validateContentTypeHeader(ResponseInterface $response): void
    {
        $header = $response->getHeaderLine('content-type');

        if (MediaType::isValid($header) === false) {
            throw new MediaTypeUnsupported($header);
        }
    }

    /**
     * Checks that the response body is well-formed JSON.
     *
     * An inline json_decode with JSON_THROW_ON_ERROR is used because responses
     * do not pass through the request-layer getParsedBody() mechanism. Empty
     * bodies are silently accepted (a 204 No Content carries no body).
     * JSON-schema linting against the JSON:API schema is not performed here.
     *
     * @throws ResponseBodyInvalidJson
     */
    public function validateJsonBody(ResponseInterface $response): void
    {
        $body = (string) $response->getBody();

        if ($body === '') {
            return;
        }

        try {
            \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ResponseBodyInvalidJson($e->getMessage(), $body);
        }
    }
}
