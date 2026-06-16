<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Operation;

use haddowg\JsonApi\Server\ResolvingServerInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ambient context shared by every operation: the {@see ResolvingServerInterface}
 * (the render contract plus serializer/hydrator resolution a handler needs) and,
 * when the operation originated from an HTTP request, the originating PSR-7
 * message.
 *
 * The HTTP request is intentionally optional and kept private behind
 * {@see httpRequest()}: an operation dispatched programmatically (constructed
 * directly rather than adapted from a PSR-7 request) has no HTTP message, and
 * {@see httpRequest()} returns `null` for it. Handlers that genuinely need the
 * raw request must therefore null-check.
 */
final readonly class OperationContext
{
    public function __construct(
        public ResolvingServerInterface $server,
        private ?ServerRequestInterface $httpRequest = null,
    ) {}

    /**
     * The originating PSR-7 request, or `null` when the operation was dispatched
     * programmatically (no HTTP message backs it).
     */
    public function httpRequest(): ?ServerRequestInterface
    {
        return $this->httpRequest;
    }
}
