<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Middleware;

use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Forces an early JSON decode of the request body so a malformed payload
 * surfaces as {@see \haddowg\JsonApi\Exception\RequestBodyInvalidJson} (→ 400)
 * here, at the edge of the chain, rather than deep inside a handler.
 *
 * It wraps the incoming PSR-7 request in a {@see JsonApiRequest} (idempotently —
 * a no-op when content negotiation already wrapped it) and passes that instance
 * down the chain, so downstream middleware and the handler share one memoized
 * parse (the "swap the request down the chain" convention; no request
 * attribute). A request with no body (GET, empty body) passes through untouched.
 *
 * The middleware builds no responses — it only throws typed exceptions that the
 * {@see ErrorHandlerMiddleware} renders — so it needs no PSR-17 factories. It
 * enforces no max-body-size limit; that is delegated to upstream infrastructure.
 */
final class RequestBodyParsingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $jsonApiRequest = $request instanceof JsonApiRequestInterface ? $request : new JsonApiRequest($request);

        // Only force the decode when a body is actually present. getParsedBody()
        // decodes the raw body with JSON_THROW_ON_ERROR and throws
        // RequestBodyInvalidJson on malformed JSON; the return value is discarded
        // here because downstream reads it from the same (wrapped) request.
        if ((string) $jsonApiRequest->getBody() !== '') {
            $jsonApiRequest->getParsedBody();
        }

        return $handler->handle($jsonApiRequest);
    }
}
