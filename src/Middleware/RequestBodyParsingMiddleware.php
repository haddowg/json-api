<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Middleware;

use haddowg\JsonApi\Negotiation\RequestValidator;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Validates the structure of the incoming request body at the edge of the chain,
 * so a malformed or non-conformant payload surfaces here rather than deep inside
 * a handler. It forces an early JSON decode (malformed JSON →
 * {@see \haddowg\JsonApi\Exception\RequestBodyInvalidJson}, 400) and checks the
 * top-level member rules (data/errors/meta presence and mutual exclusivity →
 * {@see \haddowg\JsonApi\Exception\RequiredTopLevelMembersMissing} /
 * {@see \haddowg\JsonApi\Exception\TopLevelMembersIncompatible} /
 * {@see \haddowg\JsonApi\Exception\TopLevelMemberNotAllowed}, all 400). A request
 * with no body (GET, empty body) passes through untouched.
 *
 * Both checks delegate to {@see RequestValidator} — the same extension point a
 * framework integration would reuse in its own middleware — so the shipped
 * middleware stays a thin adapter over the negotiation/validation logic.
 *
 * It wraps the incoming PSR-7 request in a {@see JsonApiRequest} (idempotently —
 * a no-op when content negotiation already wrapped it) and passes that instance
 * down the chain, so downstream middleware and the handler share one memoized
 * parse (the "swap the request down the chain" convention; no request
 * attribute).
 *
 * The middleware builds no responses — it only throws typed exceptions that the
 * {@see ErrorHandlerMiddleware} renders — so it needs no PSR-17 factories. It
 * enforces no max-body-size limit; that is delegated to upstream infrastructure.
 */
final readonly class RequestBodyParsingMiddleware implements MiddlewareInterface
{
    private RequestValidator $validator;

    public function __construct()
    {
        $this->validator = new RequestValidator();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $jsonApiRequest = $request instanceof JsonApiRequestInterface ? $request : new JsonApiRequest($request);

        $this->validator->validateJsonBody($jsonApiRequest);
        $this->validator->validateTopLevelMembers($jsonApiRequest);

        return $handler->handle($jsonApiRequest);
    }
}
