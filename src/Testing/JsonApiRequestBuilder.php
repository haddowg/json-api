<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Testing;

use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Fluent builder for a PSR-7 {@see ServerRequestInterface} carrying a JSON:API
 * request, for integration tests:
 *
 * ```php
 * $request = JsonApiRequestBuilder::post('/api/posts', $psr17, $psr17)
 *     ->withResource('posts', attributes: ['title' => 'Hello'])
 *     ->withProfile('https://example.com/profiles/x')
 *     ->build();
 * ```
 *
 * PSR-17 factories are injected (the package depends only on the PSR-17
 * interfaces, never a concrete implementation) so the builder works with any
 * provider. The `Content-Type`/`Accept` default to `application/vnd.api+json`,
 * with any profiles echoed in the media-type `profile` parameter.
 */
final class JsonApiRequestBuilder
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $resource = null;

    /**
     * @var array<string, string>
     */
    private array $query = [];

    /**
     * @var list<string>
     */
    private array $profiles = [];

    /**
     * @var array<string, string>
     */
    private array $headers = [];

    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly ServerRequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public static function get(string $uri, ServerRequestFactoryInterface $requestFactory, StreamFactoryInterface $streamFactory): self
    {
        return new self('GET', $uri, $requestFactory, $streamFactory);
    }

    public static function post(string $uri, ServerRequestFactoryInterface $requestFactory, StreamFactoryInterface $streamFactory): self
    {
        return new self('POST', $uri, $requestFactory, $streamFactory);
    }

    public static function patch(string $uri, ServerRequestFactoryInterface $requestFactory, StreamFactoryInterface $streamFactory): self
    {
        return new self('PATCH', $uri, $requestFactory, $streamFactory);
    }

    public static function delete(string $uri, ServerRequestFactoryInterface $requestFactory, StreamFactoryInterface $streamFactory): self
    {
        return new self('DELETE', $uri, $requestFactory, $streamFactory);
    }

    /**
     * @param array<string, mixed>                 $attributes
     * @param array<string, array<string, mixed>>  $relationships keyed by name, each a `{ data: … }` map
     */
    public function withResource(string $type, ?string $id = null, array $attributes = [], array $relationships = []): self
    {
        $resource = ['type' => $type];
        if ($id !== null) {
            $resource['id'] = $id;
        }
        if ($attributes !== []) {
            $resource['attributes'] = $attributes;
        }
        if ($relationships !== []) {
            $resource['relationships'] = $relationships;
        }
        $this->resource = $resource;

        return $this;
    }

    public function withQueryParam(string $key, string $value): self
    {
        $this->query[$key] = $value;

        return $this;
    }

    public function withProfile(string ...$uris): self
    {
        $this->profiles = [...$this->profiles, ...\array_values($uris)];

        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function build(): ServerRequestInterface
    {
        $uri = $this->uri;
        if ($this->query !== []) {
            $uri .= (\str_contains($uri, '?') ? '&' : '?') . \http_build_query($this->query);
        }

        $request = $this->requestFactory->createServerRequest($this->method, $uri)
            ->withHeader('Accept', $this->mediaType());

        if ($this->query !== []) {
            $request = $request->withQueryParams($this->query);
        }

        if ($this->resource !== null) {
            $body = ['data' => $this->resource];
            $json = \json_encode($body, \JSON_THROW_ON_ERROR);
            $request = $request
                ->withHeader('Content-Type', $this->mediaType())
                ->withBody($this->streamFactory->createStream($json))
                ->withParsedBody($body);
        }

        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private function mediaType(): string
    {
        $type = self::MEDIA_TYPE;
        if ($this->profiles !== []) {
            $type .= ';profile="' . \implode(' ', $this->profiles) . '"';
        }

        return $type;
    }
}
