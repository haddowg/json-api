<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Middleware\Internal;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adapts a single middleware plus the next handler into a
 * {@see RequestHandlerInterface}: handling the request runs the middleware with
 * the next handler as its delegate.
 *
 * @internal used by {@see \haddowg\JsonApi\Middleware\JsonApiMiddleware} to
 *           compose the suite into one pipeline; not part of the public API.
 */
final readonly class MiddlewareHandler implements RequestHandlerInterface
{
    public function __construct(
        private MiddlewareInterface $middleware,
        private RequestHandlerInterface $next,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->middleware->process($request, $this->next);
    }
}
