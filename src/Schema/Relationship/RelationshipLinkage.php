<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Relationship;

/**
 * The linkage `data` a rendered to-many relationship carries when the host supplies
 * it OUT-OF-BAND — through the
 * {@see \haddowg\JsonApi\Serializer\RelationshipLinkageInterface} seam — rather than
 * core reading it off the parent model's backing property.
 *
 * Its sole consumer is the Relationship Queries profile's per-page window: the host
 * (which owns the data layer) windows a to-many to page 1 of its profile
 * sort/filter and would otherwise have to WRITE that page back onto the parent's
 * relation property so core reads it as the linkage. That destructive write
 * corrupts any SIBLING relation that shares the same backing property — core reads
 * every relation's linkage off its column, so a bystander renders the windowed
 * sibling's filtered page (and, under a lazy load-state predicate, flips to
 * "loaded"). Supplying the windowed page through this VO instead leaves the parent
 * property untouched, so a shared-property bystander renders its own membership.
 *
 * The carried `$data` is the related domain value(s) for the windowed relation —
 * the same shape {@see AbstractRelationship::setData()} accepts (a list/iterable of
 * related objects). `$present` distinguishes an empty-but-rendered page (`data: []`)
 * from "no override" (the caller returns `null` from the seam for that), so an
 * override always renders a `data` member.
 */
final readonly class RelationshipLinkage
{
    /**
     * @param mixed $data the related domain value(s) for the windowed relation (the
     *                     same shape {@see AbstractRelationship::setData()} accepts)
     */
    public function __construct(
        public mixed $data,
    ) {}
}
