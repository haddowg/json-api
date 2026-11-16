<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Transformer;

use haddowg\JsonApi\Resource\DeclaresSingletonTypesInterface;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Resource\SingletonResourceInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * @internal
 */
final class SingletonType
{
    /**
     * Whether `$type`, rendered by `$serializer`, is a singleton, so the links into its own
     * endpoints hang off `/{uriType}` with no id: the serializer declares it itself, or the
     * resolver injected into it says so, or the server's `$resolver` does. The last covers a
     * serializer that decorates or overrides a singleton's resource without declaring it.
     */
    public static function of(SerializerInterface $serializer, string $type, ?SerializerResolverInterface $resolver = null): bool
    {
        if ($serializer instanceof SingletonResourceInterface && $serializer::isSingleton()) {
            return true;
        }

        $own = $serializer instanceof SerializerResolverAwareInterface ? $serializer->serializerResolver() : null;
        foreach ([$own, $resolver] as $candidate) {
            if ($candidate instanceof DeclaresSingletonTypesInterface && $candidate->isSingleton($type)) {
                return true;
            }
        }

        return false;
    }
}
