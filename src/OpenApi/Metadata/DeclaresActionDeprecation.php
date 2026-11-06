<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Action metadata that marks the action deprecated: each of its operations carries
 * `deprecated: true` in the OpenAPI document. An action on a type declared deprecated
 * through {@see DeclaresDeprecation} is marked whether or not it implements this.
 *
 * A separate, opt-in contract rather than a method on {@see ActionMetadataInterface}, so
 * an existing implementation keeps working, the same shape as {@see DeclaresActionSafety}.
 */
interface DeclaresActionDeprecation extends ActionMetadataInterface
{
    public function isDeprecated(): bool;
}
