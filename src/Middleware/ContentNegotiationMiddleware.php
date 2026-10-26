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
 * Validates JSON:API content negotiation on the incoming request: the
 * `Content-Type`/`Accept` media-type parameters (only `ext`/`profile` are
 * permitted), extension support, and query-parameter well-formedness. A write
 * (any unsafe method, bar a `DELETE` with no body) must also carry a
 * `Content-Type` of `application/vnd.api+json`; an absent header or another
 * media type is a `415`.
 *
 * Rejections throw typed exceptions ({@see \haddowg\JsonApi\Exception\MediaTypeUnsupported}
 * → 415, {@see \haddowg\JsonApi\Exception\MediaTypeUnacceptable} → 406,
 * {@see \haddowg\JsonApi\Exception\QueryParamUnrecognized} → 400) that the
 * {@see ErrorHandlerMiddleware} renders. **Profiles are advisory** — an
 * unrecognized profile is never rejected; only an unsupported `ext` fails
 * negotiation.
 *
 * This is a request-side concern: response Content-Type, `profile` echoing and
 * `Vary: Accept` are emitted by the response layer
 * ({@see \haddowg\JsonApi\Response\AbstractResponse::toPsrResponse()}), so the
 * middleware needs no server state — only the supported-extension set. It wraps
 * the request in a {@see JsonApiRequest} (idempotent) and passes it down.
 */
final readonly class ContentNegotiationMiddleware implements MiddlewareInterface
{
    private RequestValidator $validator;

    /**
     * @param string ...$supportedExtensions the extension URIs this server supports;
     *                                        none (the default) rejects any `ext`
     *                                        parameter present on the request
     */
    public function __construct(string ...$supportedExtensions)
    {
        $this->validator = new RequestValidator(...$supportedExtensions);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $jsonApiRequest = $request instanceof JsonApiRequestInterface ? $request : new JsonApiRequest($request);

        $this->validator->negotiate($jsonApiRequest, strictContentType: self::carriesDocument($jsonApiRequest));
        $this->validator->validateQueryParams($jsonApiRequest);

        return $handler->handle($jsonApiRequest);
    }

    /**
     * Whether the request must carry a JSON:API document, and so a JSON:API
     * `Content-Type`: every unsafe method, except a `DELETE` with no body, which is how
     * a resource is deleted and which clients send without a `Content-Type`.
     */
    private static function carriesDocument(JsonApiRequestInterface $request): bool
    {
        $method = \strtoupper($request->getMethod());
        if (\in_array($method, ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true)) {
            return false;
        }

        if ($method !== 'DELETE') {
            return true;
        }

        return (string) $request->getBody() !== '' || !\in_array($request->getParsedBody(), [null, []], true);
    }
}
