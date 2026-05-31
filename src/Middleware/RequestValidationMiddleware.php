<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Middleware;

use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\Internal\ProfileSchemaCollector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Optional, dev/CI middleware that validates the parsed request body against the
 * JSON:API request JSON Schema (augmented by any in-scope profile fragments).
 *
 * It runs **after** {@see RequestBodyParsingMiddleware} (so it receives the
 * already-parsed {@see JsonApiRequest} swapped down the chain) and **before** the
 * handler. A request with no body (GET, bodyless DELETE) passes through. A
 * validation failure throws {@see \haddowg\JsonApi\Exception\RequestBodyInvalidJsonApi}
 * (400), which {@see ErrorHandlerMiddleware} renders.
 *
 * The {@see DocumentValidator} is injected (it requires the optional
 * `opis/json-schema` package, so wiring fails fast if it is absent). The
 * {@see ServerInterface} provides the profile registry used to gather schema
 * fragments. Validation is **per-server opt-in**: add this middleware to a
 * server's chain only where you want it (e.g. dev/CI).
 */
final readonly class RequestValidationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ServerInterface $server,
        private DocumentValidator $validator,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $jsonApiRequest = $request instanceof JsonApiRequestInterface ? $request : new JsonApiRequest($request);

        // Only validate when a body is present. getParsedBody() decodes the raw
        // body (throwing RequestBodyInvalidJson on malformed JSON); a null result
        // is an absent/empty body and is left for the handler to reject if it
        // requires one.
        if ((string) $jsonApiRequest->getBody() !== '') {
            $document = $jsonApiRequest->getParsedBody();

            if ($document !== null) {
                $this->validator->validateRequest(
                    $document,
                    ProfileSchemaCollector::collect($this->server, $jsonApiRequest),
                );
            }
        }

        return $handler->handle($jsonApiRequest);
    }
}
