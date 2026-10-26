<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Resource\Link\DeclaredLink;

/**
 * Type metadata for a type whose resource objects carry declared links, so its
 * `<Type>ResourceLinks` schema lists each by name with its `meta` members, and its
 * operation examples show them.
 *
 * A separate, opt-in contract rather than a method on {@see TypeMetadataInterface}, so an
 * existing implementation keeps compiling, the same shape as {@see DeclaresActionLink}. An
 * adapter fills it from {@see \haddowg\JsonApi\Resource\AbstractResource::allLinks()}.
 */
interface DeclaresResourceLinks extends TypeMetadataInterface
{
    /**
     * The type's declared links, in declaration order.
     *
     * @return list<DeclaredLink>
     */
    public function declaredLinks(): array;
}
