<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Type metadata that says which of the type's operations a handler serves without
 * supporting `include`, refusing any `include` with `INCLUSION_UNSUPPORTED`.
 *
 * A separate, opt-in contract rather than a method on {@see TypeMetadataInterface}. Neither
 * core nor its adapters raise the code, so metadata that doesn't implement it never offers
 * it.
 */
interface DeclaresInclusionUnsupported extends TypeMetadataInterface
{
    /**
     * Whether the operation refuses every `include` with `INCLUSION_UNSUPPORTED`.
     */
    public function rejectsInclude(OperationKey $operation): bool;
}
