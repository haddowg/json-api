<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Type metadata that says whether a type with no field inventory, written to by a
 * hand-written hydrator, refuses linkage of the wrong cardinality with
 * `RELATIONSHIP_TYPE_INAPPROPRIATE`, as one extending
 * {@see \haddowg\JsonApi\Hydrator\AbstractHydrator} does.
 *
 * A separate, opt-in contract rather than a method on {@see TypeMetadataInterface}.
 * Metadata that doesn't implement it offers the code on such a type's writes. A type with
 * a field inventory reads linkage of its declared cardinality and never asks.
 */
interface DeclaresHydratorCardinality extends TypeMetadataInterface
{
    /**
     * Whether the type's hydrator checks the cardinality of the linkage it reads.
     */
    public function checksRelationshipCardinality(): bool;
}
