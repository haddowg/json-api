<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresDeprecation;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;

/**
 * Type metadata that declares the type, or some of its operations, deprecated.
 */
final readonly class DeprecatedTypeMetadata extends DelegatingTypeMetadata implements DeclaresDeprecation
{
    /**
     * @param list<string> $operations deprecated operation names
     */
    public function __construct(
        TypeMetadataInterface $inner,
        private bool $deprecated = false,
        private ?\DateTimeInterface $sunset = null,
        private array $operations = [],
    ) {
        parent::__construct($inner);
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated;
    }

    public function sunset(): ?\DateTimeInterface
    {
        return $this->sunset;
    }

    public function deprecatedOperations(): array
    {
        return $this->operations;
    }
}
