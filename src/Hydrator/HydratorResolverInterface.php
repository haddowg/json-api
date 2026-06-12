<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Hydrator;

/**
 * Resolves the {@see HydratorInterface} for a JSON:API resource type. An
 * operation handler uses it to deserialize a request body onto a domain object
 * without depending on the concrete {@see \haddowg\JsonApi\Server\Server}. The
 * {@see \haddowg\JsonApi\Server\Server} (its schema registry) is the production
 * implementation — the hydrator-side mirror of
 * {@see \haddowg\JsonApi\Resource\SerializerResolverInterface}.
 */
interface HydratorResolverInterface
{
    /**
     * @throws \haddowg\JsonApi\Exception\JsonApiExceptionInterface when no hydrator is registered for `$type`
     */
    public function hydratorFor(string $type): HydratorInterface;

    /**
     * Whether a hydrator is registered for `$type`.
     */
    public function hasHydratorFor(string $type): bool;
}
