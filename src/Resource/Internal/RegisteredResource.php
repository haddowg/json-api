<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Internal;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\ProvidesResourcesInterface;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * The Resource whose declarations govern a serializer, so a serializer an adapter wraps
 * in a decorator keeps its type's id encoder and rules.
 *
 * @internal
 */
final class RegisteredResource
{
    /**
     * The serializer when it is a Resource, else the Resource `$type` was registered
     * with, looked up through the serializer's own resolver and then `$resolver`.
     * `null` for a bare serializer, or when neither resolver provides Resources.
     */
    public static function of(SerializerInterface $serializer, ?string $type, ?SerializerResolverInterface $resolver = null): ?AbstractResource
    {
        if ($serializer instanceof AbstractResource) {
            return $serializer;
        }
        if ($type === null) {
            return null;
        }

        $own = $serializer instanceof SerializerResolverAwareInterface ? $serializer->serializerResolver() : null;
        foreach ([$own, $resolver] as $candidate) {
            if ($candidate instanceof ProvidesResourcesInterface && $candidate->hasResourceFor($type)) {
                return $candidate->resourceFor($type);
            }
        }

        return null;
    }
}
