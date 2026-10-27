<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Resource\Link\ActionLink;

/**
 * Type metadata for a type whose resource declares action links, so its
 * `<Type>ResourceLinks` schema lists each named action as a link, with the declared
 * description in place of the generic one.
 *
 * A separate, opt-in contract rather than a method on {@see TypeMetadataInterface}, so an
 * existing implementation keeps compiling, the same shape as {@see DeclaresResourceLinks}.
 * An adapter fills it from
 * {@see \haddowg\JsonApi\Resource\AbstractResource::allActionLinks()}.
 */
interface DeclaresActionLinks extends TypeMetadataInterface
{
    /**
     * The type's declared action links, in declaration order.
     *
     * @return list<ActionLink>
     */
    public function actionLinks(): array;
}
