<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Scope;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;

/**
 * Says which OAuth scopes an operation needs. The application supplies it; core treats
 * every scope as an opaque string.
 *
 * {@see ScopeTableBuilder} asks it once per operation when it builds the table. An
 * override declared on the metadata ({@see \haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationScopes},
 * {@see \haddowg\JsonApi\OpenApi\Metadata\DeclaresActionScopes},
 * {@see \haddowg\JsonApi\OpenApi\Metadata\DeclaresAtomicScopes}) wins, and the resolver
 * is not asked for that operation.
 */
interface OperationScopeResolverInterface
{
    /**
     * The scopes the caller's token must grant, all of them, to perform `$operation`.
     * Return `[]` when it needs none.
     *
     * A CRUD, related or relationship operation carries no safety; resolve those from the
     * key's kind and method. An action's key carries the safety it declares.
     *
     * The key names the type it acts on in {@see OperationKey::$type}. `$type` is that
     * type's metadata, or `null` for the Atomic Operations endpoint and for a type the
     * server does not describe, such as the target of a linkage-only relation, whose
     * `fetchOne` or `fetchCollection` scopes are asked for by name.
     *
     * @param TypeMetadataInterface|null $type the metadata of the type the operation acts on
     *
     * @return list<string>
     */
    public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array;
}
