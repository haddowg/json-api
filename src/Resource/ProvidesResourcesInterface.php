<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * A {@see SerializerResolverInterface} that also returns the Resource a type was registered
 * with, which may not be the serializer it renders through. {@see NestedFieldsets} reads it
 * off the server a response renders against, so a type whose serializer is an override
 * still has its `fields[TYPE]` nested fieldset members read against the fields its Resource declares,
 * the same fields its OpenAPI document projects.
 *
 * The {@see \haddowg\JsonApi\Server\Server} and its registry implement it. A resolver that
 * decorates the registry should implement it too and delegate.
 */
interface ProvidesResourcesInterface
{
    /**
     * Whether `$type` was registered with a Resource class, rather than as a bare
     * serializer and hydrator pair.
     */
    public function hasResourceFor(string $type): bool;

    /**
     * The Resource `$type` was registered with.
     *
     * @throws \haddowg\JsonApi\Exception\NoResourceRegistered when `$type` has no Resource class
     */
    public function resourceFor(string $type): AbstractResource;
}
