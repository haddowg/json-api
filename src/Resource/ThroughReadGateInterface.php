<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Filter\RelationHop;

/**
 * Host-supplied authorization for a filter or sort that reaches through a relation,
 * such as {@see Filter\WhereThrough}: whether the caller may read what the relation
 * holds at all. A {@see RelationAuthorizerInterface} implementation that the server
 * carries implements it too, and {@see Filter\ThroughReadAccess} reads it from there.
 *
 * The question has no parent row, because one filter matches every candidate parent at
 * once. A host answers from the rules it can decide without one: the relation's
 * declared {@see Field\RelationInterface::securityRead()}, or the related types' own
 * collection and read rules. Rows the related type's scope hides are the data layer's
 * concern, not this one's.
 */
interface ThroughReadGateInterface
{
    /**
     * Whether the caller may read the data `$hop`'s relation holds, for every parent.
     * When it answers `false` an adapter refuses a filter the client asked for with a
     * `403`, and a filter it did not ask for matches no row through the hop.
     */
    public function canReadThrough(RelationHop $hop, JsonApiRequestInterface $request): bool;
}
