<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Server\ServerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A success response whose body is not a JSON:API document, returned by a custom action
 * that declares a {@see \haddowg\JsonApi\OpenApi\Metadata\RawResult}: a CSV export, a
 * PDF, an image. The body is a string, a stream resource or a PSR-7 stream, and is sent
 * as it is under the given `Content-Type`.
 *
 * It renders with `Vary: Accept`, since the action picked its media type from the
 * request's `Accept` header ({@see \haddowg\JsonApi\Negotiation\AcceptNegotiator}).
 * Errors never take this shape: an action that fails throws, and the error renders as a
 * JSON:API document.
 *
 * Immutable in the response layer's clone-then-assign style.
 */
final class RawResponse
{
    /**
     * @var string|resource|StreamInterface
     */
    private mixed $body;

    /**
     * @var array<string, string>
     */
    private array $headers = [];

    /**
     * @param string|resource|StreamInterface $body
     * @param array<string, string>           $headers
     */
    public function __construct(
        mixed $body,
        private string $contentType,
        private int $status = 200,
        array $headers = [],
    ) {
        if (!\is_string($body) && !$body instanceof StreamInterface && !(\is_resource($body) && \get_resource_type($body) === 'stream')) {
            throw new \InvalidArgumentException(\sprintf('A raw response body must be a string, a stream resource or a PSR-7 stream; got %s.', \get_debug_type($body)));
        }
        if (\trim($contentType) === '') {
            throw new \InvalidArgumentException('A raw response needs a Content-Type.');
        }

        $this->body = $body;
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
    }

    /**
     * @return string|resource|StreamInterface
     */
    public function body(): mixed
    {
        return $this->body;
    }

    public function contentType(): string
    {
        return $this->contentType;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function withStatus(int $status): self
    {
        $self = clone $this;
        $self->status = $status;

        return $self;
    }

    public function withHeader(string $name, string $value): self
    {
        $self = clone $this;
        $self->headers[$name] = $value;

        return $self;
    }

    /**
     * Renders the response as PSR-7: the status, the `Content-Type`, `Vary: Accept`,
     * then the configured headers, which may override either.
     */
    public function toPsrResponse(ServerInterface $server, ServerRequestInterface $request): ResponseInterface
    {
        $streams = $server->streamFactory();
        $stream = match (true) {
            $this->body instanceof StreamInterface => $this->body,
            \is_string($this->body) => $streams->createStream($this->body),
            default => $streams->createStreamFromResource($this->body),
        };

        $response = $server->responseFactory()
            ->createResponse($this->status)
            ->withHeader('Content-Type', $this->contentType)
            ->withHeader('Vary', 'Accept')
            ->withBody($stream);

        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
