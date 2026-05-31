<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Validation\Internal;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Server\ResourceRegistry;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Validation\SchemaCompiler;

/**
 * Compiles the per-resource JSON Schema for the resource type a request targets,
 * in the request's create/update context, ready to add to the validator's
 * `$additionalSchemas` composition list.
 *
 * Reachable only when the server is the concrete {@see Server} (it owns the
 * {@see ResourceRegistry}); the bare {@see ServerInterface} render contract has no
 * registry, so this returns no schema in that case (validation falls back to the
 * base + profile fragments). The body's `data.type` selects the resource; an
 * unregistered or absent type contributes nothing. Compiled schemas are memoized
 * per `[type, context]`.
 *
 * @internal
 */
final class ResourceSchemaCollector
{
    /**
     * @var array<string, object>
     */
    private static array $cache = [];

    /**
     * @return list<object> the compiled per-resource schema (zero or one entry)
     */
    public static function collect(ServerInterface $server, JsonApiRequestInterface $request): array
    {
        if (!$server instanceof Server) {
            return [];
        }

        $type = $request->getResourceType();
        if (!\is_string($type) || $type === '' || !$server->resources()->has($type)) {
            return [];
        }

        $resource = $server->resources()->resourceFor($type);

        $creating = \strtoupper($request->getMethod()) === 'POST';
        $key = $type . ($creating ? ':create' : ':update');

        return [self::$cache[$key] ??= (new SchemaCompiler())->compile($resource, $creating)];
    }
}
