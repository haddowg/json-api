<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Resource\Field\FieldInterface;

/**
 * Relation metadata that declares the members its `identifierMeta()` resolver adds to each
 * linkage identifier's `meta`. The projector types those members on the response linkage,
 * as it types a `belongsToMany` relation's `meta.pivot`.
 *
 * A separate, opt-in contract rather than a method on {@see RelationMetadataInterface},
 * so an existing implementation keeps working untouched, the same shape as
 * {@see DeclaresIdentifiedMembers}.
 */
interface DeclaresIdentifierMeta extends RelationMetadataInterface
{
    /**
     * The declared identifier meta members. Report
     * {@see \haddowg\JsonApi\Resource\Field\AbstractRelationValue::identifierMetaFields()}
     * for a core relation.
     *
     * @return list<FieldInterface>
     */
    public function identifierMetaFields(): array;
}
