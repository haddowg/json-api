<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresNamedOperationExtensions;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;

/**
 * Type metadata that declares extensions by operation name only, with no type-wide or
 * CRUD set.
 */
final readonly class NamedExtensionsTypeMetadata extends DelegatingTypeMetadata implements DeclaresNamedOperationExtensions
{
    /**
     * @param array<string, array<string, mixed>> $named keyed by operation name
     */
    public function __construct(TypeMetadataInterface $inner, private array $named)
    {
        parent::__construct($inner);
    }

    public function namedOperationExtensions(): array
    {
        return $this->named;
    }
}
