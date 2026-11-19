<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Resource\Field\AdditionalProperties;

/**
 * Opt-in for a {@see RelationMetadataInterface} whose pivot fields or identifier meta
 * members declare an {@see AdditionalProperties} entry, which opens that object to members
 * it does not name. Without it, or with `null`, each declared object is closed.
 */
interface DeclaresOpenRelationMeta extends RelationMetadataInterface
{
    /**
     * The entry among the relation's pivot fields, or `null`.
     */
    public function pivotAdditionalProperties(): ?AdditionalProperties;

    /**
     * The entry among the relation's identifier meta members, or `null`.
     */
    public function identifierMetaAdditionalProperties(): ?AdditionalProperties;
}
