<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Type metadata that marks the type, or some of its operations, deprecated in the
 * OpenAPI document. An integration fills it from the same declaration that drives its
 * `Deprecation` and `Sunset` response headers.
 *
 * A separate, opt-in contract rather than methods on {@see TypeMetadataInterface}, so an
 * existing implementation keeps working untouched. A type that does not implement it
 * projects with no deprecation markers.
 */
interface DeclaresDeprecation extends TypeMetadataInterface
{
    /**
     * Whether the whole type is deprecated. Every operation the projector emits for it
     * carries `deprecated: true`: its CRUD operations, its related and relationship
     * endpoints and its custom actions.
     */
    public function isDeprecated(): bool;

    /**
     * When the type's operations may stop responding, or `null`. Each of the type's
     * operations gets a sentence naming the date appended to its description. It does
     * not mark anything deprecated on its own.
     */
    public function sunset(): ?\DateTimeInterface;

    /**
     * The operations deprecated on their own, by name, in the grammar
     * {@see DeclaresOperationExamples::operationExamples()} is keyed by (see
     * {@see OperationKey}): `fetchCollection`, `fetchOne`, `create`, `update`, `delete`,
     * and for a relation `fetchRelated.<relation>`, `fetchRelationship.<relation>`,
     * `updateRelationship.<relation>`, `addRelationship.<relation>` and
     * `removeRelationship.<relation>`. A name the type does not expose fails the
     * projection. An action declares its own deprecation through
     * {@see DeclaresActionDeprecation}.
     *
     * @return list<string>
     */
    public function deprecatedOperations(): array;
}
