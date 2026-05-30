<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Request;

use haddowg\JsonApi\Exception\RequestBodyInvalidJson;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Base request wrapper that delegates all PSR-7 ServerRequestInterface methods to the wrapped request.
 *
 * Immutable via clone+withXxx() pattern: each mutating PSR-7 method clones $this and updates
 * the inner $serverRequest on the clone. The class intentionally cannot be `readonly` because:
 * (1) PSR-7 wither delegation requires `$self = clone $this` followed by property mutation on the clone,
 * (2) subclasses cache lazily-parsed query params that must be invalidated on clone.
 *
 * Implements ServerRequestInterface directly so that PHP allows `static` covariant return types on
 * the wither methods (PHP requires the interface to be declared on the same class that introduces the
 * method for covariance to be recognised).
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 */
abstract class AbstractRequest implements ServerRequestInterface
{
    protected ServerRequestInterface $serverRequest;

    abstract protected function headerChanged(string $name): void;

    abstract protected function queryParamChanged(string $name): void;

    public function __construct(ServerRequestInterface $request)
    {
        $this->serverRequest = $request;
    }

    public function getProtocolVersion(): string
    {
        return $this->serverRequest->getProtocolVersion();
    }

    /** @return static */
    public function withProtocolVersion(string $version): static
    {
        $self = clone $this;
        $self->serverRequest = $this->serverRequest->withProtocolVersion($version);

        return $self;
    }

    /** @return string[][] */
    public function getHeaders(): array
    {
        return $this->serverRequest->getHeaders();
    }

    public function hasHeader(string $name): bool
    {
        return $this->serverRequest->hasHeader($name);
    }

    /** @return string[] */
    public function getHeader(string $name): array
    {
        return $this->serverRequest->getHeader($name);
    }

    public function getHeaderLine(string $name): string
    {
        return $this->serverRequest->getHeaderLine($name);
    }

    /**
     * @param string|string[] $value
     *
     * @return static
     */
    public function withHeader(string $name, mixed $value): static
    {
        $self = clone $this;
        $self->serverRequest = $this->serverRequest->withHeader($name, $value);
        $self->headerChanged($name);

        return $self;
    }

    /**
     * @param string|string[] $value
     *
     * @return static
     */
    public function withAddedHeader(string $name, mixed $value): static
    {
        $self = clone $this;
        $self->serverRequest = $this->serverRequest->withAddedHeader($name, $value);
        $self->headerChanged($name);

        return $self;
    }

    /** @return static */
    public function withoutHeader(string $name): static
    {
        $self = clone $this;
        $self->serverRequest = $this->serverRequest->withoutHeader($name);
        $self->headerChanged($name);

        return $self;
    }

    public function getBody(): StreamInterface
    {
        return $this->serverRequest->getBody();
    }

    /** @return static */
    public function withBody(StreamInterface $body): static
    {
        $self = clone $this;
        $self->serverRequest = $this->serverRequest->withBody($body);

        return $self;
    }

    public function getRequestTarget(): string
    {
        return $this->serverRequest->getRequestTarget();
    }

    /** @return static */
    public function withRequestTarget(string $requestTarget): static
    {
        $self = clone $this;
        $self->serverRequest = $this->serverRequest->withRequestTarget($requestTarget);

        return $self;
    }

    public function getMethod(): string
    {
        return $this->serverRequest->getMethod();
    }

    /** @return static */
    public function withMethod(string $method): static
    {
        $self = clone $this;
        $self->serverRequest = $this->serverRequest->withMethod($method);

        return $self;
    }

    public function getUri(): UriInterface
    {
        return $this->serverRequest->getUri();
    }

    /** @return static */
    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $self = clone $this;
        $self->serverRequest = $this->serverRequest->withUri($uri, $preserveHost);

        return $self;
    }

    /** @return array<string, mixed> */
    public function getServerParams(): array
    {
        return $this->serverRequest->getServerParams();
    }

    /** @return array<string, string> */
    public function getCookieParams(): array
    {
        return $this->serverRequest->getCookieParams();
    }

    /**
     * @param array<string, string> $cookies
     *
     * @return static
     */
    public function withCookieParams(array $cookies): static
    {
        $self = clone $this;
        $self->serverRequest = $this->serverRequest->withCookieParams($cookies);

        return $self;
    }

    /** @return array<string, mixed> */
    public function getQueryParams(): array
    {
        return $this->serverRequest->getQueryParams();
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return static
     */
    public function withQueryParams(array $query): static
    {
        $self = clone $this;
        $self->serverRequest = $this->serverRequest->withQueryParams($query);

        foreach ($query as $name => $value) {
            $self->queryParamChanged((string) $name);
        }

        return $self;
    }

    public function getQueryParam(string $name, mixed $default = null): mixed
    {
        $queryParams = $this->serverRequest->getQueryParams();

        return $queryParams[$name] ?? $default;
    }

    /** @return static */
    public function withQueryParam(string $name, mixed $value): static
    {
        $self = clone $this;
        $queryParams = $this->serverRequest->getQueryParams();
        $queryParams[$name] = $value;
        $self->serverRequest = $this->serverRequest->withQueryParams($queryParams);
        $self->queryParamChanged($name);

        return $self;
    }

    /** @return array<string, mixed> */
    public function getUploadedFiles(): array
    {
        return $this->serverRequest->getUploadedFiles();
    }

    /**
     * @param array<string, mixed> $uploadedFiles
     *
     * @return static
     */
    public function withUploadedFiles(array $uploadedFiles): static
    {
        $self = clone $this;
        $self->serverRequest = $this->serverRequest->withUploadedFiles($uploadedFiles);

        return $self;
    }

    /** @return array<string, mixed>|object|null */
    public function getParsedBody(): array|object|null
    {
        $parsedBody = $this->serverRequest->getParsedBody();

        if ($parsedBody === null || $parsedBody === []) {
            $rawBody = (string) $this->serverRequest->getBody();

            if ($rawBody === '') {
                return null;
            }

            try {
                /** @var array<string, mixed>|null $decoded */
                $decoded = \json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new RequestBodyInvalidJson($e->getMessage(), $rawBody);
            }

            return $decoded;
        }

        return $parsedBody;
    }

    /**
     * @param array<string, mixed>|object|null $data
     *
     * @return static
     */
    public function withParsedBody(mixed $data): static
    {
        $self = clone $this;
        $self->serverRequest = $this->serverRequest->withParsedBody($data);

        return $self;
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->serverRequest->getAttributes();
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->serverRequest->getAttribute($name, $default);
    }

    /** @return static */
    public function withAttribute(string $name, mixed $value): static
    {
        $self = clone $this;
        $self->serverRequest = $this->serverRequest->withAttribute($name, $value);

        return $self;
    }

    /** @return static */
    public function withoutAttribute(string $name): static
    {
        $self = clone $this;
        $self->serverRequest = $this->serverRequest->withoutAttribute($name);

        return $self;
    }
}
