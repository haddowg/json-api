<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Transformer;

use haddowg\JsonApi\Resource\DeclaresSingletonTypesInterface;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SingletonResourceInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * @internal
 */
final class SingletonType
{
    /**
     * Whether `$type`, rendered by `$serializer`, is a singleton, so the links into its own
     * endpoints hang off `/{uriType}` with no id: the resolver injected into the serializer
     * says so, or the serializer declares it itself.
     */
    public static function of(SerializerInterface $serializer, string $type): bool
    {
        if ($serializer instanceof SingletonResourceInterface && $serializer::isSingleton()) {
            return true;
        }

        $resolver = $serializer instanceof SerializerResolverAwareInterface ? $serializer->serializerResolver() : null;

        return $resolver instanceof DeclaresSingletonTypesInterface && $resolver->isSingleton($type);
    }
}
