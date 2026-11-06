<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Relation metadata that says whether the relation is deprecated, so the relation's
 * property under `relationships` carries `deprecated: true` in the read and write
 * resource schemas. An integration fills it from the relation field's
 * {@see \haddowg\JsonApi\Resource\Deprecatable::isDeprecated()}.
 *
 * A separate, opt-in contract rather than a method on {@see RelationMetadataInterface},
 * so an existing implementation keeps working untouched, the same shape as
 * {@see DeclaresWriteOnlyRelation}. It is documentation only: the relation's related
 * and relationship endpoints are not marked by it.
 */
interface DeclaresDeprecatedRelation extends RelationMetadataInterface
{
    public function isDeprecated(): bool;
}
