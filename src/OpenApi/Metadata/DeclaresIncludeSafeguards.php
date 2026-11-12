<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Type metadata that reports the include safeguards the type applies when it is the root of
 * a document, so `INCLUSION_NOT_ALLOWED` and `INCLUSION_DEPTH_EXCEEDED` are offered only
 * where a path can break them.
 *
 * A separate, opt-in contract rather than methods on {@see TypeMetadataInterface}, the
 * same shape as {@see DeclaresResourceClass}. Metadata that doesn't implement it offers
 * both codes wherever the type is a root. Report what the type's
 * {@see \haddowg\JsonApi\Serializer\IncludeControlsInterface} returns.
 */
interface DeclaresIncludeSafeguards extends TypeMetadataInterface
{
    /**
     * The include paths the type allows as a root, or `null` for no allow-list.
     *
     * @return list<string>|null
     */
    public function allowedIncludePaths(): ?array;

    /**
     * The include paths the type refuses as a root, with everything below them.
     *
     * @return list<string>
     */
    public function excludedIncludePaths(): array;

    /**
     * The type's own maximum include depth, or `null` to leave the server's
     * ({@see DeclaresMaxIncludeDepth}) in force.
     */
    public function maxIncludeDepth(): ?int;
}
