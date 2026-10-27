<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Action metadata that declares the scopes the action needs, overriding the
 * application's {@see \haddowg\JsonApi\Scope\OperationScopeResolverInterface} for every
 * method the action answers to.
 */
interface DeclaresActionScopes extends ActionMetadataInterface
{
    /**
     * The required scopes, `[]` for none, or `null` to ask the resolver.
     *
     * @return list<string>|null
     */
    public function scopes(): ?array;
}
