<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * A serializer that opts in to receiving the {@see SerializerResolverInterface} —
 * so it can resolve related serializers and render relationships. The registry
 * injects the resolver on first resolution into any resolved serializer (or
 * resource) implementing this; {@see AbstractResource} implements it, but a
 * standalone serializer can opt in to render relations without extending the base.
 */
interface SerializerResolverAwareInterface
{
    /**
     * Injects the resolver relationships use to serialize related resources.
     */
    public function setSerializerResolver(SerializerResolverInterface $resolver): void;
}
