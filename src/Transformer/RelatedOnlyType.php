<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Transformer;

use haddowg\JsonApi\Resource\DeclaresRelatedOnlyTypesInterface;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * @internal
 */
final class RelatedOnlyType
{
    /**
     * Whether `$type`, rendered by `$serializer`, is related-only according to the
     * resolver injected into the serializer. A serializer with no resolver, or one
     * whose resolver doesn't say, renders every link as before.
     */
    public static function of(SerializerInterface $serializer, string $type): bool
    {
        if (!$serializer instanceof SerializerResolverAwareInterface) {
            return false;
        }

        $resolver = $serializer->serializerResolver();

        return $resolver instanceof DeclaresRelatedOnlyTypesInterface && $resolver->isRelatedOnly($type);
    }
}
