<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Transformer;

use haddowg\JsonApi\Exception\InclusionDepthExceeded;
use haddowg\JsonApi\Exception\InclusionNotAllowed;
use haddowg\JsonApi\Exception\InclusionUnrecognized;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * Checks a request's `?include` paths without any data to render.
 *
 * {@see rootSafeguards()} applies the two root-scoped safeguards (the allowed
 * include paths and the maximum include depth) that every resource document runs
 * before it renders. {@see validateTree()} walks the requested include tree
 * through the registry's resources and rejects a path that no declared relation
 * could ever satisfy, so an empty primary result gets the same `400` a non-empty
 * one does.
 *
 * @internal
 */
final class IncludePathGuard
{
    /**
     * Applies the allowed-include-paths whitelist, then the maximum include depth,
     * for a render rooted at `$primary`, and returns the normalised effective depth
     * cap (`null` = unlimited). `$primary` is `null` when the root has no single
     * serializer (a polymorphic related endpoint), which leaves only the server
     * default depth in force.
     *
     * @param list<string> $requestedPaths
     *
     * @throws InclusionNotAllowed    a path is outside the primary's whitelist
     * @throws InclusionDepthExceeded a path is deeper than the effective cap
     */
    public static function rootSafeguards(array $requestedPaths, ?SerializerInterface $primary, ?int $serverDefaultDepth): ?int
    {
        if ($primary instanceof IncludeControlsInterface) {
            $allowed = $primary->getAllowedIncludePaths();
            if ($allowed !== null) {
                // A requested path is permitted when it is a listed path or an
                // ancestor of one: listing `posts.author` implies `posts` is
                // traversable. A sibling or unlisted nested path is rejected.
                $offending = \array_values(\array_filter(
                    $requestedPaths,
                    static function (string $path) use ($allowed): bool {
                        foreach ($allowed as $allowedPath) {
                            if ($allowedPath === $path || \str_starts_with($allowedPath, $path . '.')) {
                                return false;
                            }
                        }

                        return true;
                    },
                ));
                if ($offending !== []) {
                    throw new InclusionNotAllowed($offending);
                }
            }
        }

        $override = $primary instanceof IncludeControlsInterface ? $primary->maxIncludeDepth() : null;
        $effective = $override ?? $serverDefaultDepth;
        if ($effective !== null && $effective <= 0) {
            $effective = null;
        }

        if ($effective !== null) {
            $tooDeep = \array_values(\array_filter(
                $requestedPaths,
                static fn(string $path): bool => \substr_count($path, '.') + 1 > $effective,
            ));
            if ($tooDeep !== []) {
                throw new InclusionDepthExceeded($tooDeep, $effective);
            }
        }

        return $effective;
    }

    /**
     * Walks the requested include tree from `$rootTypes` and rejects a segment
     * that no candidate type declares as a visible relationship
     * ({@see InclusionUnrecognized}), or that every candidate type declaring it
     * has made unconditionally non-includable ({@see InclusionNotAllowed}).
     *
     * A segment through a polymorphic relation continues into the union of its
     * related types, so a nested segment is valid when any of them declares it.
     * The walk only judges types whose registered serializer is an
     * {@see AbstractResource} (the only kind with a relation inventory that needs
     * no domain object); below any other type it stops and leaves the path to the
     * render-time check. For the same reason a relation hidden or non-includable
     * only by a per-request predicate passes here and is judged per object while
     * rendering.
     *
     * @param list<string> $rootTypes
     *
     * @throws InclusionUnrecognized
     * @throws InclusionNotAllowed
     */
    public static function validateTree(
        JsonApiRequestInterface $request,
        array $rootTypes,
        SerializerResolverInterface $resolver,
    ): void {
        self::walk($request, '', $rootTypes, $resolver);
    }

    /**
     * @param list<string> $types
     */
    private static function walk(
        JsonApiRequestInterface $request,
        string $basePath,
        array $types,
        SerializerResolverInterface $resolver,
    ): void {
        $names = $request->getIncludedRelationships($basePath);
        if ($names === []) {
            return;
        }

        $resources = [];
        foreach ($types as $type) {
            if (!$resolver->hasSerializerFor($type)) {
                return;
            }

            $serializer = $resolver->serializerFor($type);
            if (!$serializer instanceof AbstractResource) {
                return;
            }

            $resources[$type] = $serializer;
        }

        if ($resources === []) {
            return;
        }

        $unrecognized = [];
        $notAllowed = [];
        /** @var list<array{string, list<string>}> $next */
        $next = [];

        foreach ($names as $name) {
            $declared = false;
            $includable = false;
            $relatedTypes = [];

            foreach ($resources as $type => $resource) {
                $relation = $resource->relationNamed($name);
                if ($relation === null) {
                    continue;
                }

                // Mirrors AbstractResource::getRelationships(): a sparse-by-default
                // relation renders only when `fields[type]` names it.
                if ($relation->isSparseByDefault() && !\in_array($name, $request->getIncludedFields($type), true)) {
                    continue;
                }

                $declared = true;
                if ($relation->isIncludable()) {
                    $includable = true;
                }

                foreach ($relation->relatedTypes() as $relatedType) {
                    $relatedTypes[$relatedType] = true;
                }
            }

            $path = ($basePath !== '' ? $basePath . '.' : '') . $name;

            if (!$declared) {
                $unrecognized[] = $path;
            } elseif (!$includable) {
                $notAllowed[] = $path;
            } else {
                $next[] = [$path, \array_keys($relatedTypes)];
            }
        }

        if ($unrecognized !== []) {
            throw new InclusionUnrecognized($unrecognized);
        }

        if ($notAllowed !== []) {
            throw new InclusionNotAllowed($notAllowed);
        }

        foreach ($next as [$path, $relatedTypes]) {
            if ($relatedTypes !== []) {
                self::walk($request, $path, $relatedTypes, $resolver);
            }
        }
    }
}
