<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Exception\NoResourceRegistered;
use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Profile\ProfileRegistry;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\ResolvingServerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Minimal {@see ResolvingServerInterface} test double backed by the Nyholm PSR-17
 * factory (which implements both the response and stream factories). It registers
 * no resources, so the serializer/hydrator resolvers report nothing and throw if
 * asked to resolve — operation-plumbing tests that use it never resolve.
 */
final class StubServer implements ResolvingServerInterface
{
    private readonly Psr17Factory $psr17Factory;

    private readonly ProfileRegistry $profiles;

    /**
     * @param array<string, mixed> $defaultMeta
     */
    public function __construct(
        private readonly string $baseUri = '',
        private readonly string $jsonApiVersion = JsonApiObject::VERSION,
        private readonly array $defaultMeta = [],
        private readonly int $encodeOptions = 0,
        ?ProfileRegistry $profiles = null,
        private readonly ?int $maxIncludeDepth = null,
    ) {
        $this->psr17Factory = new Psr17Factory();
        $this->profiles = $profiles ?? new ProfileRegistry();
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

    public function maxIncludeDepth(): ?int
    {
        return $this->maxIncludeDepth;
    }

    public function profiles(): ProfileRegistry
    {
        return $this->profiles;
    }

    public function responseFactory(): ResponseFactoryInterface
    {
        return $this->psr17Factory;
    }

    public function streamFactory(): StreamFactoryInterface
    {
        return $this->psr17Factory;
    }

    public function serializerFor(string $type): SerializerInterface
    {
        throw new NoResourceRegistered($type);
    }

    public function hasSerializerFor(string $type): bool
    {
        return false;
    }

    public function hydratorFor(string $type): HydratorInterface
    {
        throw new NoResourceRegistered($type);
    }

    public function hasHydratorFor(string $type): bool
    {
        return false;
    }

    public function relationshipLoadState(): ?RelationshipLoadStateInterface
    {
        return null;
    }

    public function relationshipCount(): ?\haddowg\JsonApi\Serializer\RelationshipCountInterface
    {
        return null;
    }

    public function relationshipPagination(): ?\haddowg\JsonApi\Serializer\RelationshipPaginationInterface
    {
        return null;
    }
}
