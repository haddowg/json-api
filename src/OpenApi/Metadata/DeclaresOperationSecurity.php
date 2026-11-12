<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Server metadata that says, for each operation, whether the adapter's security layer can
 * answer it with `ACCESS_DENIED` or `AUTHENTICATION_REQUIRED`. Core never raises either:
 * a Symfony firewall, a Laravel middleware or a security expression on the type, relation
 * or action does, so only the adapter can tell.
 *
 * A separate, opt-in contract rather than methods on {@see ServerMetadataInterface}, the
 * same shape as {@see DeclaresMethodNotAllowed}. Metadata that doesn't implement it offers
 * both codes on every operation. The arguments match
 * {@see \haddowg\JsonApi\Scope\OperationScopeResolverInterface::requiredScopes()}: the
 * Atomic Operations endpoint is asked about with a `null` type and
 * {@see OperationKey::atomic()}.
 */
interface DeclaresOperationSecurity extends ServerMetadataInterface
{
    /**
     * Whether an authenticated caller can be refused the operation with `ACCESS_DENIED`:
     * a security gate on the operation, its type, the relation or the action it runs.
     */
    public function canDenyAccess(?TypeMetadataInterface $type, OperationKey $operation): bool;

    /**
     * Whether an unauthenticated caller can be refused the operation with
     * `AUTHENTICATION_REQUIRED`: the route sits behind authentication, or a gate on it can
     * reject an anonymous caller.
     */
    public function canRequireAuthentication(?TypeMetadataInterface $type, OperationKey $operation): bool;
}
