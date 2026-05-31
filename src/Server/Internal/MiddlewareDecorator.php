<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Server\Internal;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Wraps one PSR-15 {@see MiddlewareInterface} together with the next
 * {@see RequestHandlerInterface} into a single handler, so a middleware list can
 * be folded (innermost-first) into one composed handler for {@see \haddowg\JsonApi\Server\Server::handle()}.
 *
 * @internal
 */
final readonly class MiddlewareDecorator implements RequestHandlerInterface
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
