<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;

/**
 * The JSON:API types a server's projection **describes with a resource object** — the
 * authoritative answer to "which types does this server's generated contract cover?".
 *
 * A framework integration emits a **second** artifact from the same metadata (the
 * per-type JSON Schema bundle served at `/schemas.json`) and keys it from
 * {@see forServer()}, so the two artifacts cover one agreed type set rather than two that
 * happen to coincide. That is why this is public API rather than a private detail of the
 * projector.
 *
 * On a projectable server the answer is just the registered types, because a resource
 * object is a shape claim and only a registration carries the field inventory to back
 * one. {@see relatedOnly()} reports the types that break that rule — a related endpoint
 * aimed at something this server does not register — and a non-empty result means
 * {@see OpenApiProjector::project()} refuses with a
 * {@see RelatedTypeNotRegistered}. Read it to fail a build before the export runs.
 *
 * A related type that is only ever a **linkage** target (no relation exposes its related
 * endpoint) is fine unregistered and deliberately absent from the set: the document gives
 * it a `ResourceIdentifier`, which is `{type, id}` and asserts no shape.
 *
 * @see OpenApiProjector::project() the document side of the same rule
 */
final class ProjectedTypes
{
    /**
     * Every type the server's projection describes with a resource object: the
     * registered types in registration order, then anything {@see relatedOnly()} reports
     * — which is empty for every server that projects, so in practice this is
     * {@see registered()}. The concatenation stays so a caller reading it never has to
     * know which of the two it is looking at.
     *
     * @return list<string>
     */
    public static function forServer(ServerMetadataInterface $server): array
    {
        return [...self::registered($server), ...self::relatedOnly($server)];
    }

    /**
     * The types registered on the server, in registration order — each projected from
     * its own field inventory.
     *
     * @return list<string>
     */
    public static function registered(ServerMetadataInterface $server): array
    {
        $types = [];
        foreach ($server->types() as $type) {
            $types[] = $type->type();
        }

        return $types;
    }

    /**
     * The types a relation exposes its **related** endpoint to without the server
     * registering them — a fault, and exactly the set
     * {@see OpenApiProjector::project()} throws {@see RelatedTypeNotRegistered} over.
     * Deduped, in first-encountered order; empty for a server that projects.
     *
     * Call it to fail a build before the export runs. The exception carries the parent
     * type and relation as well, which is what makes it the better diagnostic once
     * projection is on the table.
     *
     * @return list<string>
     */
    public static function relatedOnly(ServerMetadataInterface $server): array
    {
        $seen = \array_fill_keys(self::registered($server), true);

        // Accumulated as a list rather than read back off the dedup map's keys: a
        // numerically-named type ("2024") is a legal JSON:API member name and would come
        // back off an array key as an int.
        $related = [];
        foreach ($server->types() as $type) {
            foreach ($type->relations() as $relation) {
                if (!$relation->exposesRelatedEndpoint()) {
                    continue;
                }

                foreach ($relation->relatedTypes() as $relatedType) {
                    if (isset($seen[$relatedType])) {
                        continue;
                    }
                    $seen[$relatedType] = true;
                    $related[] = $relatedType;
                }
            }
        }

        return $related;
    }
}
