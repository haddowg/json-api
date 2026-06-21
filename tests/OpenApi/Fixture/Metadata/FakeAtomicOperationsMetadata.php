<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\AtomicOperationsMetadataInterface;
use haddowg\JsonApi\OpenApi\SecurityRequirement;

/**
 * An in-core {@see AtomicOperationsMetadataInterface} fixture — a plain value
 * carrier so the projector tests need no Symfony.
 */
final class FakeAtomicOperationsMetadata implements AtomicOperationsMetadataInterface
{
    /**
     * @param list<SecurityRequirement> $security
     */
    public function __construct(
        private readonly string $path = '/operations',
        private readonly string $tag = 'Atomic Operations',
        private readonly array $security = [],
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function tag(): string
    {
        return $this->tag;
    }

    public function security(): array
    {
        return $this->security;
    }
}
