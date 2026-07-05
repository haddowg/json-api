<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Operation;

use haddowg\JsonApi\Exception\ApplicationError;
use haddowg\JsonApi\Exception\ResourceIdConflict;
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
     *
     * A resource `PATCH` whose document `data.id` is present and differs from
     * the endpoint id throws {@see ResourceIdConflict} (a 409), the id half of
     * the spec's "type and id do not match the server's endpoint" rule (the type
     * half is enforced by the hydrator). Both ids must be present *and strings*
     * for the check to fire — an absent body id is a separate concern the
     * hydrator owns, and a non-string body id is rejected as a 400
     * ({@see \haddowg\JsonApi\Exception\ResourceIdInvalid}) by the hydrator's own
     * id validation, so it can never reach a mismatching-yet-accepted state here.
     *
     * The id half lives here, so it guards the **HTTP dispatch path only**. The
     * type half is in the hydrator and therefore covers every path (HTTP and
     * programmatic). A caller that constructs an {@see UpdateResourceOperation}
     * directly — bypassing this factory, as a future Atomic Operations backend
     * would for a `ref`/`href`-targeted update sub-op whose body is still client
     * input — must enforce the id/target match itself; it is not inherited from
     * this factory.
     */
    public function fromRequest(
        JsonApiRequestInterface $request,
        Target $target,
        OperationContext $context,
    ): JsonApiOperationInterface {
        $query = QueryParameters::fromRequest($request);
        $hasRelationship = $target->hasRelationship();
        $method = \strtoupper($request->getMethod());

        if ($method === 'PATCH' && $hasRelationship === false) {
            $bodyId = $request->getResourceId();
            if (\is_string($bodyId) && $bodyId !== '' && $target->id !== null && $bodyId !== $target->id) {
                throw new ResourceIdConflict($bodyId, $target->id);
            }
        }

        return match ($method) {
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
