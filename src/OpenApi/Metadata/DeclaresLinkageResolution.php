<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Relation metadata that says whether linkage a create sends for the relation can name a
 * resource the data layer cannot find, or the caller cannot see, answering
 * `RESOURCE_NOT_FOUND`: the related type is loaded to attach it, through a provider that
 * scopes what the caller sees, or by an id that may decode to nothing.
 *
 * A separate, opt-in contract rather than a method on {@see RelationMetadataInterface}.
 * Metadata that doesn't implement it offers the code on every create sending the relation.
 */
interface DeclaresLinkageResolution extends RelationMetadataInterface
{
    /**
     * Whether linkage to a related resource that is missing, or hidden from the caller,
     * is refused with `RESOURCE_NOT_FOUND`.
     */
    public function canMissLinkage(): bool;
}
