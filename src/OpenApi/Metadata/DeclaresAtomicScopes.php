<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Atomic Operations metadata that declares the scopes the endpoint itself needs,
 * overriding the application's {@see \haddowg\JsonApi\Scope\OperationScopeResolverInterface}.
 * Each operation in a batch is still checked against its own scopes.
 */
interface DeclaresAtomicScopes extends AtomicOperationsMetadataInterface
{
    /**
     * The required scopes, `[]` for none, or `null` to ask the resolver.
     *
     * @return list<string>|null
     */
    public function scopes(): ?array;
}
