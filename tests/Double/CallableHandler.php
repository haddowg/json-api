<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A PSR-15 request handler backed by a closure, for wiring the innermost handler
 * of a middleware chain in tests. The closure receives the (possibly wrapped)
 * request, so tests can assert what reached the handler, return a response, or
 * throw.
 */
final readonly class CallableHandler implements RequestHandlerInterface
{
    /**
     * @param \Closure(ServerRequestInterface): ResponseInterface $handle
     */
    public function __construct(private \Closure $handle) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->handle)($request);
    }
}
