<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\Internal\RenderedDocument;
use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Server\ServerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Base for the public response value objects.
 *
 * Holds the document-level members common to every response (meta, links, the
 * jsonapi object), the response headers and an optional per-response encode-flag
 * override, plus the fluent withers that set them and the render template that
 * turns a response into a PSR-7 message.
 *
 * Immutability follows the {@see \haddowg\JsonApi\Request\AbstractRequest}
 * convention: the class is NOT `readonly`, properties are `protected`, and each
 * wither does clone-then-assign (`$self = clone $this; $self->x = …; return
 * $self;`) — a `readonly` property cannot be reassigned on a clone under PHP 8.3.
 *
 * The render path is serializer-free until the very end: {@see render()} builds a
 * PHP array via the transformer and {@see toPsrResponse()} encodes it.
 */
abstract class AbstractResponse
{
    /**
     * @var array<string, mixed>
     */
    protected array $meta = [];

    protected ?DocumentLinks $links = null;

    protected ?JsonApiObject $jsonApi = null;

    /**
     * @var array<string, string>
     */
    protected array $headers = [];

    protected ?int $encodeOptions = null;

    /**
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta): static
    {
        $self = clone $this;
        $self->meta = $meta;

        return $self;
    }

    public function withLinks(?DocumentLinks $links): static
    {
        $self = clone $this;
        $self->links = $links;

        return $self;
    }

    public function withJsonApi(?JsonApiObject $jsonApi): static
    {
        $self = clone $this;
        $self->jsonApi = $jsonApi;

        return $self;
    }

    public function withHeader(string $name, string $value): static
    {
        $self = clone $this;
        $self->headers[$name] = $value;

        return $self;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): static
    {
        $self = clone $this;
        $self->headers = $headers;

        return $self;
    }

    public function withEncodeOptions(int $encodeOptions): static
    {
        $self = clone $this;
        $self->encodeOptions = $encodeOptions;

        return $self;
    }

    /**
     * Renders the response value object into a PSR-7 response: builds the body
     * array via {@see render()}, JSON-encodes it with the resolved flags and the
     * fixed `application/vnd.api+json` content type, then applies any configured
     * headers.
     *
     * @throws \JsonException when the body cannot be encoded
     */
    final public function toPsrResponse(ServerInterface $server, ServerRequestInterface $request): ResponseInterface
    {
        $jsonApiRequest = $request instanceof JsonApiRequestInterface ? $request : new JsonApiRequest($request);

        $rendered = $this->render($server, $jsonApiRequest);

        // JSON_THROW_ON_ERROR is passed inline (not via a variable) so PHPStan narrows
        // json_encode()'s return to string; an unencodable document throws \JsonException.
        $json = \json_encode(
            $rendered->body,
            \JSON_THROW_ON_ERROR | ($this->encodeOptions ?? $server->encodeOptions()),
        );

        $response = $server->responseFactory()
            ->createResponse($rendered->status)
            ->withHeader('Content-Type', 'application/vnd.api+json')
            ->withBody($server->streamFactory()->createStream($json));

        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Resolves the document's `jsonapi` object: an explicitly set one, otherwise
     * one built from the server defaults.
     */
    protected function resolveJsonApi(ServerInterface $server): JsonApiObject
    {
        return $this->jsonApi ?? new JsonApiObject($server->jsonApiVersion(), $server->defaultMeta());
    }

    /**
     * Builds the JSON:API document body and the HTTP status for this response.
     */
    abstract protected function render(ServerInterface $server, JsonApiRequestInterface $request): RenderedDocument;
}
