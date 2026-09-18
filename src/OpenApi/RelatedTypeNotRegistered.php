<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * Thrown when a relation exposes its **related** endpoint to a JSON:API type the server
 * being projected does not register.
 *
 * A related endpoint returns the related type as primary data, so the document has to
 * state that type's shape. With nothing registered there is no field inventory to state
 * it from, and the only shape the projector could publish is an open one that promises
 * nothing — a claim this server cannot honour, carrying the same JSON:API `type` as the
 * real thing in whichever document does register it.
 *
 * This is a wiring-time configuration error, not a JSON:API request error: it is a
 * {@see \LogicException} and deliberately does **not** implement
 * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} — it should surface as a
 * build failure to fix, never as an error document in a response.
 *
 * @see ProjectedTypes::relatedOnly() the same offending set, as plain type names, for a
 *      caller that wants to detect the fault before projecting
 */
final class RelatedTypeNotRegistered extends \LogicException
{
    public function __construct(
        public readonly string $server,
        public readonly string $parentType,
        public readonly string $relation,
        public readonly string $relatedType,
    ) {
        parent::__construct(\sprintf(
            'Relation "%s" on type "%s" exposes its related endpoint to type "%s", which is not registered on server "%s". '
            . 'That endpoint returns a "%s" resource object and this server has no field inventory to describe one. '
            . 'Register "%s" on this server, point the relation at a type that is registered (a reduced second type — "public-profiles" beside "users" — where the full one belongs to another server), '
            . 'or call withoutRelatedEndpoint() on the relation to keep it linkage-only.',
            $relation,
            $parentType,
            $relatedType,
            $server,
            $relatedType,
            $relatedType,
        ));
    }
}
