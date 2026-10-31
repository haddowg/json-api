<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;

/**
 * Resolves the types a document's `?include` paths reach, so the `fields[<type>]`
 * parameters and the typed `included` member read one answer.
 *
 * @internal
 */
final class IncludeReachability
{
    /**
     * The server's types keyed by their JSON:API type.
     *
     * @return array<string, TypeMetadataInterface>
     */
    public static function byType(ServerMetadataInterface $server): array
    {
        $byType = [];
        foreach ($server->types() as $type) {
            $byType[$type->type()] = $type;
        }

        return $byType;
    }

    /**
     * The related type(s) a dotted `?include` path (e.g. `author.company`) resolves to,
     * walking the relation graph segment by segment from `$origin`. A polymorphic segment
     * branches into every member type and an unresolvable segment prunes that branch.
     * Returns the deepest segment's related types only.
     *
     * @param array<string, TypeMetadataInterface> $byType
     * @return list<string>
     */
    public static function terminalTypes(string $origin, string $path, array $byType): array
    {
        $current = [$origin];
        foreach (\explode('.', $path) as $segment) {
            $next = [];
            foreach ($current as $currentType) {
                $owner = $byType[$currentType] ?? null;
                if ($owner === null) {
                    continue;
                }
                foreach ($owner->relations() as $relation) {
                    if ($relation->name() !== $segment) {
                        continue;
                    }
                    foreach ($relation->relatedTypes() as $relatedType) {
                        if (!\in_array($relatedType, $next, true)) {
                            $next[] = $relatedType;
                        }
                    }
                }
            }
            if ($next === []) {
                return [];
            }
            $current = $next;
        }

        return $current;
    }

    /**
     * The registered types a compound document can carry in `included`: every terminal
     * type of every path, walked from each root, deduped in first-discovery order. Since
     * the advertised paths list each intermediate hop as a path of its own, the
     * terminals cover every type an include renders. A type the server does not register
     * has no resource component, so it is left out.
     *
     * @param list<string>                         $roots
     * @param list<string>                         $paths
     * @param array<string, TypeMetadataInterface> $byType
     * @return list<string>
     */
    public static function includedTypes(array $roots, array $paths, array $byType): array
    {
        $reached = [];
        foreach ($roots as $root) {
            foreach ($paths as $path) {
                foreach (self::terminalTypes($root, $path, $byType) as $type) {
                    if (isset($byType[$type]) && !\in_array($type, $reached, true)) {
                        $reached[] = $type;
                    }
                }
            }
        }

        return $reached;
    }
}
