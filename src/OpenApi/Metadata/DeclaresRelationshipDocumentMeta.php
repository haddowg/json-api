<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\RelationshipMetaContext;

/**
 * Opt-in for a {@see TypeMetadataInterface} whose relationship writes answer a meta-only
 * `200` declaring its top-level `meta`, as
 * {@see \haddowg\JsonApi\Serializer\DeclaresRelationshipDocumentMetaInterface} does on the
 * type itself.
 */
interface DeclaresRelationshipDocumentMeta
{
    /**
     * The members of the top-level `meta` of the meta-only document the relationship write
     * `$context` answers with. It is called without a request.
     *
     * @return iterable<FieldInterface|FieldBuilderInterface>
     */
    public function relationshipDocumentMeta(RelationshipMetaContext $context): iterable;
}
