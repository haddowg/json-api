<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Relation metadata that names the URI segment the relation's related and relationship
 * endpoints are served under, when it differs from the relationship member name.
 *
 * A separate, opt-in contract rather than a method on {@see RelationMetadataInterface},
 * so an existing implementation keeps working untouched, the same shape as
 * {@see DeclaresWriteOnlyRelation}. Metadata that doesn't implement it keys the
 * relation's paths by {@see RelationMetadataInterface::name()}.
 */
interface DeclaresRelationUriFieldName extends RelationMetadataInterface
{
    /**
     * The relation's URI segment: `/{type}/{id}/{uriFieldName}` and
     * `/{type}/{id}/relationships/{uriFieldName}`. Mirror the core relation's
     * {@see \haddowg\JsonApi\Resource\Field\AbstractRelationValue::uriFieldName()}, which
     * is the name itself unless `withUriFieldName()` set one.
     */
    public function uriFieldName(): string;
}
