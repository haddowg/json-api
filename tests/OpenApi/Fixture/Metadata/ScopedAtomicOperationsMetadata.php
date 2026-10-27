<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresAtomicScopes;

/**
 * Atomic Operations metadata that declares the endpoint's own scopes.
 */
final class ScopedAtomicOperationsMetadata implements DeclaresAtomicScopes
{
    /**
     * @param list<string>|null $scopes
     */
    public function __construct(private readonly ?array $scopes) {}

    public function scopes(): ?array
    {
        return $this->scopes;
    }

    public function path(): string
    {
        return '/operations';
    }

    public function tag(): string
    {
        return 'Atomic Operations';
    }

    public function security(): array
    {
        return [];
    }
}
