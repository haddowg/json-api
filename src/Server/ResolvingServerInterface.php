<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Server;

use haddowg\JsonApi\Hydrator\HydratorResolverInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;

/**
 * The server surface an {@see \haddowg\JsonApi\Operation\OperationHandlerInterface}
 * consumes: the render contract ({@see ServerInterface}) plus type-keyed
 * serializer and hydrator resolution.
 *
 * An operation handler receives this via
 * {@see \haddowg\JsonApi\Operation\OperationContext::$server}, so it can resolve
 * the serializer or hydrator for a type through an interface rather than
 * downcasting to the concrete {@see Server}. The concrete {@see Server} is the
 * production implementation.
 */
interface ResolvingServerInterface extends ServerInterface, SerializerResolverInterface, HydratorResolverInterface {}
