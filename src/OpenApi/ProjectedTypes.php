<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;

/**
 * The JSON:API types a server's projection **describes with a resource object** — the
 * authoritative answer to "which types does this server's generated contract cover?".
 *
 * A server's document covers more than the types registered on it. A relation may target
 * a type the server does not register (it belongs to another server, or is described
 * without being registered at all), and when that relation exposes its **related**
 * endpoint the projector synthesizes a permissive `<RelatedType>Resource` so the endpoint
 * that returns one has something to `$ref`. Those synthesized types are part of the
 * contract: the server really does return them.
 *
 * The rule matters beyond the OpenAPI document because a framework integration emits a
 * **second** artifact from the same metadata — the per-type JSON Schema bundle served at
 * `/schemas.json`. Deriving that bundle from {@see ServerMetadataInterface::types()}
 * alone silently omits every related-only type, so the two artifacts describe different
 * type sets and a client validating a related endpoint's response finds no schema for it.
 * Keying that bundle from {@see forServer()} is what keeps the two aligned, and is why
 * this is public API rather than a private detail of the projector.
 *
 * A related type that is only ever a **linkage** target (no relation exposes its related
 * endpoint) is deliberately absent: the document gives it a `ResourceIdentifier` and no
 * resource object, so there is no resource-object contract to describe.
 *
 * @see OpenApiProjector::project() the document side of the same rule
 */
final class ProjectedTypes
{
    /**
     * Every type the server's projection describes with a resource object: the
     * registered types in registration order, then the related-only types in the order
     * their first exposing relation is walked.
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
     * The types the server describes **only** as a relation target: not registered, but
     * reached by a relation exposing its related endpoint, so the projection synthesizes
     * a permissive resource object for them. Deduped, in first-encountered order.
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
