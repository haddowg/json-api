<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Type metadata for a type that may be related-only: it serves no endpoint of its own and
 * is reached only as another type's related or included resources, or as a custom
 * action's request document. A related-only type's resource objects carry no `self`
 * link, so its `<Type>ResourceLinks` lists no `self` and its examples show none.
 *
 * Its metadata exposes no operations, no actions and no relationship or related endpoint;
 * the projector refuses a related-only type that does. A separate, opt-in contract rather
 * than a method on {@see TypeMetadataInterface}, so an existing implementation keeps
 * compiling. An adapter fills it from the same declaration it passes to
 * {@see \haddowg\JsonApi\Server\Server::withRelatedOnlyTypes()}.
 */
interface DeclaresRelatedOnly extends TypeMetadataInterface
{
    public function isRelatedOnly(): bool;
}
