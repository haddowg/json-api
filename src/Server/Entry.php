<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Server;

use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A single {@see ResourceRegistry} entry, keyed in the registry by {@see $type}.
 *
 * Two shapes share one record:
 *  - a **Resource entry** carries a {@see AbstractResource} class-string
 *    ({@see $resource}) plus any serializer / hydrator override class-strings;
 *    its `$type` is read statically from `$resource::$type` at register time.
 *  - a **bare pair** has `$resource === null` and supplies a serializer and/or
 *    hydrator class-string directly under an explicit `$type` (a bare
 *    serializer/hydrator has no static `::$type` to derive a key from).
 *
 * @internal
 */
final readonly class Entry
{
    /**
     * @param class-string<AbstractResource>|null    $resource a Resource class-string, or null for a bare pair
     * @param class-string<SerializerInterface>|null $serializer
     * @param class-string<HydratorInterface>|null   $hydrator
     * @param string                                 $type     the registry key (a Resource's static `$type`, or the explicit key of a bare pair)
     */
    public function __construct(
        public ?string $resource,
        public ?string $serializer = null,
        public ?string $hydrator = null,
        public string $type = '',
    ) {}
}
