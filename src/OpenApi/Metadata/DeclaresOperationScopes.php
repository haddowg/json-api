<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Type metadata that declares the scopes some of its operations need, overriding the
 * application's {@see \haddowg\JsonApi\Scope\OperationScopeResolverInterface}.
 */
interface DeclaresOperationScopes extends TypeMetadataInterface
{
    /**
     * Operation key name → required scopes, keyed like
     * {@see DeclaresOperationExamples::operationExamples()} (see {@see OperationKey}). `[]`
     * declares that the operation needs no scope. A key that is not an operation of the
     * type fails the scope table build.
     *
     * @return array<string, list<string>>
     */
    public function operationScopes(): array;
}
