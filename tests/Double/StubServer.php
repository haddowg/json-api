<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Server\ServerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Minimal {@see ServerInterface} test double backed by the Nyholm PSR-17
 * factory (which implements both the response and stream factories).
 */
final class StubServer implements ServerInterface
{
    private readonly Psr17Factory $psr17Factory;

    /**
     * @param array<string, mixed> $defaultMeta
     */
    public function __construct(
        private readonly string $baseUri = '',
        private readonly string $jsonApiVersion = JsonApiObject::VERSION,
        private readonly array $defaultMeta = [],
        private readonly int $encodeOptions = 0,
    ) {
        $this->psr17Factory = new Psr17Factory();
    }

    public function baseUri(): string
    {
        return $this->baseUri;
    }

    public function jsonApiVersion(): string
    {
        return $this->jsonApiVersion;
    }

    public function defaultMeta(): array
    {
        return $this->defaultMeta;
    }

    public function encodeOptions(): int
    {
        return $this->encodeOptions;
    }

    public function responseFactory(): ResponseFactoryInterface
    {
        return $this->psr17Factory;
    }

    public function streamFactory(): StreamFactoryInterface
    {
        return $this->psr17Factory;
    }
}
