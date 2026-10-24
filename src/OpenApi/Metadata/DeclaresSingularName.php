<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Type metadata that names one resource of the type in generated prose, for a type
 * whose singular the English inflection gets wrong or reads awkwardly.
 *
 * A separate, opt-in contract rather than a method on {@see TypeMetadataInterface}, so
 * an existing implementation keeps working untouched, the same shape as
 * {@see DeclaresOperationExtensions}. Metadata that doesn't implement it, or returns
 * `null`, gets the inflected singular of the type name.
 */
interface DeclaresSingularName extends TypeMetadataInterface
{
    /**
     * The singular noun the generated summaries and descriptions use for one resource
     * of this type, as words (`media item`, `person`), without an article. The
     * projector picks "a" or "an" from its first word. Mirror
     * {@see \haddowg\JsonApi\Resource\AbstractResource::getSingularName()} when the
     * type is backed by a resource.
     */
    public function singularName(): ?string;
}
