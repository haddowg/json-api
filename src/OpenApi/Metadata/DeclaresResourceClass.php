<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Type metadata that names the class the type is declared by, so code reading the
 * metadata, such as an {@see \haddowg\JsonApi\Scope\OperationScopeResolverInterface}, can
 * read the application's own attributes off it.
 */
interface DeclaresResourceClass extends TypeMetadataInterface
{
    /**
     * The resource or serializer class, or `null` when the type has none.
     *
     * @return class-string|null
     */
    public function resourceClass(): ?string;
}
