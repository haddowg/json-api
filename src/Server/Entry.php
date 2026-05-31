<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Server;

use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A single {@see SchemaRegistry} entry: the resource (schema) class plus any
 * serializer / hydrator override class-strings.
 *
 * @internal
 */
final readonly class Entry
{
    /**
     * @param class-string<AbstractResource>         $resource
     * @param class-string<SerializerInterface>|null $serializer
     * @param class-string<HydratorInterface>|null   $hydrator
     */
    public function __construct(
        public string $resource,
        public ?string $serializer = null,
        public ?string $hydrator = null,
    ) {}
}
