<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\MetaContext;

/**
 * A type's resource object as a self-contained JSON Schema, the document an adapter's
 * standalone schema export serves for each type. It has no `components` to reference, so
 * every narrowing is inline.
 *
 * Its attributes are {@see SchemaProjector::projectResourceObject()}'s, as in the OpenAPI
 * document. Its `meta` and its linkage identifiers' `meta` are typed as the OpenAPI document
 * types them: the type's read `resourceMeta()`, beside the `pivot` a pivot relation's related
 * endpoint renders, and per relation the declared identifier meta and the read pivot fields.
 * A type or relation declaring none keeps the open object. The rest of each relationship
 * object, and `links`, stay open, as the document describes one resource object read
 * wherever it appears.
 */
final class StandaloneResourceObject
{
    /**
     * The resource object of `$type`, whose attributes `$fields` declare, typed against
     * what `$server` declares for it. A type `$server` does not describe gets the plain
     * projection.
     *
     * @param iterable<FieldInterface> $fields
     */
    public static function project(ServerMetadataInterface $server, string $type, iterable $fields, SchemaProjector $schemas): Schema
    {
        $resource = $schemas->projectResourceObject($type, $fields);
        $metadata = null;
        foreach ($server->types() as $candidate) {
            if ($candidate->type() === $type) {
                $metadata = $candidate;

                break;
            }
        }
        if ($metadata === null) {
            return $resource;
        }

        $meta = self::resourceMeta($server, $metadata, $schemas);
        if ($meta !== null) {
            $resource = $resource->withProperty('meta', $meta);
        }

        $relationships = [];
        foreach ($metadata->relations() as $relation) {
            $identifierMeta = self::identifierMeta($relation, $schemas);
            if ($identifierMeta !== null) {
                $relationships[$relation->name()] = Schema::ofType('object')->withProperty('data', self::linkage($relation, $identifierMeta));
            }
        }

        return $relationships === []
            ? $resource
            : $resource->withProperty('relationships', Schema::ofType('object')->withProperties($relationships));
    }

    /**
     * The type's read resource `meta`, or `null` when it declares none.
     */
    private static function resourceMeta(ServerMetadataInterface $server, TypeMetadataInterface $type, SchemaProjector $schemas): ?Schema
    {
        $read = TypedRequestMeta::forResource($type, MetaContext::read());
        if ($read->isEmpty()) {
            return null;
        }

        $schema = $schemas->projectMetaObject($read);

        return RelationFacts::isPivotTarget($server, $type->type()) && $read->field('pivot') === null
            ? $schema->withProperty('pivot', Schema::ofType('object')->withDescription('The pivot values of the link a related endpoint reached this resource through.'))
            : $schema;
    }

    /**
     * A linkage identifier's `meta` for `$relation`: its declared identifier meta, a closed
     * object listing `pivot` among its properties when the relation has a pivot too, else
     * the pivot alone. `null` when the relation declares neither.
     */
    private static function identifierMeta(RelationMetadataInterface $relation, SchemaProjector $schemas): ?Schema
    {
        $pivot = RelationFacts::hasPivot($relation)
            ? $schemas->projectReadPivotObject($relation->pivotFields(), null, RelationFacts::pivotAdditional($relation))
            : null;
        if (RelationFacts::hasIdentifierMeta($relation)) {
            $meta = $schemas->projectReadObject(RelationFacts::identifierMetaFields($relation))
                ->withAdditionalProperties($schemas->additionalMembers(RelationFacts::identifierMetaAdditional($relation)));

            return $pivot === null ? $meta : $meta->withProperty('pivot', $pivot);
        }

        return $pivot === null ? null : Schema::ofType('object')->withProperty('pivot', $pivot);
    }

    /**
     * The relationship's `data`: identifiers whose `meta` is `$meta`, a list for a to-many
     * and one or `null` for a to-one.
     */
    private static function linkage(RelationMetadataInterface $relation, Schema $meta): Schema
    {
        $identifier = Schema::ofType('object')->withProperty('meta', $meta);

        return $relation->isToMany()
            ? Schema::ofType('array')->withItems($identifier)
            : Schema::create()->withType('object', nullable: true)->withProperty('meta', $meta);
    }
}
