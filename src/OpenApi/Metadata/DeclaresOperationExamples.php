<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Type metadata that replaces or switches off the examples the projector generates for
 * the type's operations.
 *
 * It is a separate, opt-in contract rather than a method on {@see TypeMetadataInterface},
 * so an existing implementation keeps working untouched.
 */
interface DeclaresOperationExamples extends TypeMetadataInterface
{
    /**
     * Example overrides keyed by operation name: `fetchCollection`, `fetchOne`, `create`,
     * `update`, `delete`, and for a relation `fetchRelated.<relation>`,
     * `fetchRelationship.<relation>`, `updateRelationship.<relation>`,
     * `addRelationship.<relation>` and `removeRelationship.<relation>`. A `false` or
     * `null` value emits no example for that operation; an {@see OperationExample}
     * replaces the parts it names. An operation left out keeps its generated example.
     * A name the type does not expose fails the projection.
     *
     * @return array<string, OperationExample|false|null>
     */
    public function operationExamples(): array;
}
