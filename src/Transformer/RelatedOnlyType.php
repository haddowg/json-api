<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Transformer;

use haddowg\JsonApi\Resource\DeclaresRelatedOnlyTypesInterface;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * @internal
 */
final class RelatedOnlyType
{
    /**
     * Whether `$type`, rendered by `$serializer`, is related-only according to the
     * resolver injected into the serializer or the server's `$resolver`. When neither
     * says, every link renders as before.
     */
    public static function of(SerializerInterface $serializer, string $type, ?SerializerResolverInterface $resolver = null): bool
    {
        $own = $serializer instanceof SerializerResolverAwareInterface ? $serializer->serializerResolver() : null;
        foreach ([$own, $resolver] as $candidate) {
            if ($candidate instanceof DeclaresRelatedOnlyTypesInterface && $candidate->isRelatedOnly($type)) {
                return true;
            }
        }

        return false;
    }
}
