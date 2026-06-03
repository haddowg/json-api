<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Operation;

use haddowg\JsonApi\Exception\ApplicationError;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * The single source of truth for the JSON:API dispatch decision: builds the one
 * concrete {@see JsonApiOperationInterface} matching the request's HTTP method
 * crossed with the shape of the {@see Target} (whether it names a relationship,
 * and if so whether it is the relationship-linkage endpoint).
 *
 * This is a public, stateless seam. It takes the already-parsed
 * {@see JsonApiRequestInterface} (so the body source and
 * {@see QueryParameters::fromRequest()} read from the same memoized wrapper and
 * the factory never re-wraps — wrapping/idempotency stays the caller's
 * responsibility) and the {@see OperationContext} explicitly (each caller
 * constructs the context with its own choice of HTTP request, and that choice
 * must remain the caller's). It does not handle a missing target: the signature
 * requires a non-null {@see Target}, so that concern stays at the adapter edge.
 */
final class OperationFactory
{
    /**
     * Build the operation for a parsed request, explicit target and context.
     *
     * Read verbs (`GET`, plus `DELETE` on a resource) yield body-less
     * operations; the five mutating verbs yield body-carrying operations whose
     * body is the same `$request` passed in. An unhandled HTTP method throws
     * {@see ApplicationError} (a 500), exactly as the inline dispatch did.
     */
    public function fromRequest(
        JsonApiRequestInterface $request,
        Target $target,
        OperationContext $context,
    ): JsonApiOperationInterface {
        $query = QueryParameters::fromRequest($request);
        $hasRelationship = $target->hasRelationship();

        return match (\strtoupper($request->getMethod())) {
            'GET' => match (true) {
                $hasRelationship === false => new FetchResourceOperation($target, $query, $context),
                $target->isRelationshipEndpoint => new FetchRelationshipOperation($target, $query, $context),
                default => new FetchRelatedOperation($target, $query, $context),
            },
            'POST' => $hasRelationship
                ? new AddToRelationshipOperation($target, $query, $context, $request)
                : new CreateResourceOperation($target, $query, $context, $request),
            'PATCH' => $hasRelationship
                ? new UpdateRelationshipOperation($target, $query, $context, $request)
                : new UpdateResourceOperation($target, $query, $context, $request),
            'DELETE' => $hasRelationship
                ? new RemoveFromRelationshipOperation($target, $query, $context, $request)
                : new DeleteResourceOperation($target, $query, $context),
            default => throw new ApplicationError(),
        };
    }
}
