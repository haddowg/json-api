<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Middleware;

use haddowg\JsonApi\Exception\ResponseBodyInvalidJsonApi;
use haddowg\JsonApi\Negotiation\ResponseValidator;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\Internal\ProfileSchemaCollector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Optional, dev/CI middleware that validates the **outgoing** JSON:API document
 * against the response JSON Schema (augmented by any in-scope profile fragments).
 *
 * A failing response is a server bug, not a client error. By default it
 * **throws** {@see ResponseBodyInvalidJsonApi} (500) so the failure is loud in
 * dev/CI; pass `$throwOnViolation = false` to downgrade to logging the violations
 * and passing the response through unchanged (production-soak mode).
 *
 * Placement: just **inside** {@see ErrorHandlerMiddleware} and **outside**
 * negotiation/body-parsing. Its validation runs as the response unwinds (after
 * the handler has produced a rendered PSR-7 response), and a thrown
 * {@see ResponseBodyInvalidJsonApi} bubbles up to the outermost error handler,
 * which renders the 500. Only `application/vnd.api+json` responses with a body
 * are validated; everything else (e.g. a `204`, a redirect) passes through.
 *
 * The {@see DocumentValidator} is injected (requires `opis/json-schema`).
 * Validation is **per-server opt-in**.
 */
final readonly class ResponseValidationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ServerInterface $server,
        private DocumentValidator $validator,
        private bool $throwOnViolation = true,
        private ?LoggerInterface $logger = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($this->isJsonApiResponse($response) === false) {
            return $response;
        }

        // Delegate JSON well-formedness to the response validator (the same
        // extension point a framework integration reuses); a malformed body —
        // our own serializer's bug — throws ResponseBodyInvalidJson. The decoded
        // document is returned so it is not parsed twice.
        $document = (new ResponseValidator())->validateJsonBody($response);
        if ($response->getBody()->isSeekable()) {
            $response->getBody()->rewind();
        }

        if ($document === null) {
            return $response;
        }

        $jsonApiRequest = $request instanceof JsonApiRequestInterface ? $request : new JsonApiRequest($request);

        try {
            $this->validator->validateResponse(
                $document,
                ProfileSchemaCollector::collect($this->server, $jsonApiRequest),
            );
        } catch (ResponseBodyInvalidJsonApi $exception) {
            if ($this->throwOnViolation) {
                throw $exception;
            }

            $this->logger?->error('JSON:API response document failed schema validation', [
                'exception' => $exception,
                'violations' => $exception->validationErrors,
            ]);
        }

        return $response;
    }

    private function isJsonApiResponse(ResponseInterface $response): bool
    {
        return \str_contains($response->getHeaderLine('Content-Type'), 'application/vnd.api+json');
    }
}
