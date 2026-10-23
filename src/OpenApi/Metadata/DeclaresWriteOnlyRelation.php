<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Relation metadata that says whether the relation is write-only: accepted on a
 * write, never rendered in a response.
 *
 * A separate, opt-in contract rather than a method on
 * {@see RelationMetadataInterface}, so an existing implementation keeps working
 * untouched, the same shape as {@see DeclaresOperationExtensions}. Metadata that
 * doesn't implement it projects the relation as readable.
 */
interface DeclaresWriteOnlyRelation extends RelationMetadataInterface
{
    /**
     * Whether the relation is **unconditionally** write-only. A write-only relation
     * is left out of the read resource schema, the `fields[type]` and `?withCount`
     * vocabularies, and has no related endpoint or relationship `GET`; it keeps its
     * place in the create/update schemas and its relationship-mutation operations,
     * which answer `204`. A relation write-only only by a request predicate reports
     * `false`, so the document describes the superset, as it does for an attribute.
     */
    public function isWriteOnly(): bool;
}
