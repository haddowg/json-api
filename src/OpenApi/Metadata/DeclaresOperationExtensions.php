<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Type metadata that declares OpenAPI vendor extensions (`x-…`) for the type's
 * operations, such as `x-feature: agency` on a feature-gated resource.
 *
 * It is a **separate**, opt-in contract rather than methods on
 * {@see TypeMetadataInterface}, so an existing implementation keeps working
 * untouched, the same shape as {@see ContributesErrorCodes}.
 *
 * Names are normalised to the `x-` prefix (`feature` emits `x-feature`); a reserved
 * `x-oai-`/`x-oas-` name fails the projection with an `\InvalidArgumentException`.
 */
interface DeclaresOperationExtensions extends TypeMetadataInterface
{
    /**
     * Extensions stamped on every operation the projector emits for this type: the
     * CRUD operations, its related and relationship endpoints, and its custom
     * actions.
     *
     * @return array<string, mixed>
     */
    public function extensions(): array;

    /**
     * Extensions for one CRUD operation, merged over {@see extensions()} (the
     * operation's value wins on the same key). Only consulted for an operation the
     * type exposes.
     *
     * @return array<string, mixed>
     */
    public function operationExtensions(OperationType $operation): array;
}
