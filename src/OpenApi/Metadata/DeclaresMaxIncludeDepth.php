<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Server metadata that says how deep an `include` path may go when the root type sets no
 * depth of its own, so `INCLUSION_DEPTH_EXCEEDED` is offered only where a path can be too
 * deep.
 *
 * A separate, opt-in contract rather than a method on {@see ServerMetadataInterface}, the
 * same shape as {@see DeclaresReadOnlyAttributeRejection}. Without it, a root that declares
 * no depth of its own ({@see DeclaresIncludeSafeguards}) offers the code.
 */
interface DeclaresMaxIncludeDepth extends ServerMetadataInterface
{
    /**
     * Mirrors {@see \haddowg\JsonApi\Server\Server::maxIncludeDepth()}: the server's default
     * maximum depth, or `null` (or a value below one) for none.
     */
    public function maxIncludeDepth(): ?int;
}
