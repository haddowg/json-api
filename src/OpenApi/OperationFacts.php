<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Schema\Profile\CountableProfile;

/**
 * What one enumerated operation takes: the `page` schema it advertises, the `withCount`
 * tokens it accepts and whether it reads a request document. The operation projector and the
 * error catalogue both read them here, so an error tied to a parameter is offered exactly
 * where the parameter is.
 *
 * @internal
 */
final class OperationFacts
{
    /**
     * The page schema the operation's `page` parameter carries, or `null` when it takes none:
     * a type's collection, a to-many's related collection, and a to-many's relationship read
     * when its linkage is a queryable collection (monomorphic, or stored pairs).
     */
    public static function pageSchema(TypeMetadataInterface $type, EnumeratedOperation $operation): ?Schema
    {
        $relation = $operation->relation;

        return match ($operation->key->kind) {
            OperationKind::FetchCollection => $type->pageSchema(),
            OperationKind::FetchRelated => $relation !== null && $relation->isToMany() ? $relation->pageSchema() : null,
            OperationKind::FetchRelationship => $relation !== null && self::pagesLinkage($relation) ? $relation->pageSchema() : null,
            default => null,
        };
    }

    /**
     * The `withCount` tokens the operation accepts, `[]` when it takes no `withCount`. The
     * Countable profile's registration is the caller's to check.
     *
     * @return list<string>
     */
    public static function withCountTokens(TypeMetadataInterface $type, EnumeratedOperation $operation, ServerMetadataInterface $server): array
    {
        $relation = $operation->relation;

        return match ($operation->key->kind) {
            OperationKind::FetchCollection => self::collectionWithCountTokens($type),
            OperationKind::FetchRelated => $relation !== null && $relation->isToMany() ? self::relatedWithCountTokens($relation, $server) : [],
            OperationKind::FetchRelationship => $relation !== null && self::pagesLinkage($relation) ? self::relationshipWithCountTokens($relation) : [],
            default => [],
        };
    }

    /**
     * Whether the operation reads a JSON:API request document: a create, an update, a
     * relationship mutation, or an action taking a document. A resource `DELETE` sends none.
     */
    public static function readsDocument(EnumeratedOperation $operation): bool
    {
        return match ($operation->key->kind) {
            OperationKind::Create,
            OperationKind::Update,
            OperationKind::UpdateRelationship,
            OperationKind::AddRelationship,
            OperationKind::RemoveRelationship => true,
            OperationKind::Action => $operation->action?->inputMode() === ActionInputMode::Document,
            default => false,
        };
    }

    /**
     * Whether the operation hydrates a resource's attributes and id from its document: a
     * create, an update, or an action taking a document, which hydrates its input type.
     */
    public static function hydratesResource(EnumeratedOperation $operation): bool
    {
        return match ($operation->key->kind) {
            OperationKind::Create, OperationKind::Update => true,
            OperationKind::Action => $operation->action?->inputMode() === ActionInputMode::Document,
            default => false,
        };
    }

    /**
     * The valid `withCount` tokens for a primary collection `GET /{type}`: `_self_` when
     * the type is {@see TypeMetadataInterface::isCountable()} (its collection advertises
     * the count), plus every countable relation of the type (counted per primary item).
     *
     * @return list<string>
     */
    public static function collectionWithCountTokens(TypeMetadataInterface $type): array
    {
        $tokens = $type->isCountable() ? [CountableProfile::SELF_TOKEN] : [];

        return [...$tokens, ...self::countableRelationNames($type)];
    }

    /**
     * The valid `withCount` tokens for a related to-many `GET /{type}/{id}/{rel}`:
     * `_self_` when the relation is {@see RelationMetadataInterface::isCountable()} (its
     * related collection advertises the count), plus every countable relation of the
     * RELATED type (counted per related item across the page). A polymorphic relation's
     * page mixes types, so it takes a relation any of its related types counts.
     *
     * @return list<string>
     */
    public static function relatedWithCountTokens(RelationMetadataInterface $relation, ServerMetadataInterface $server): array
    {
        $tokens = $relation->isCountable() ? [CountableProfile::SELF_TOKEN] : [];

        $relationNames = [];
        foreach ($relation->relatedTypes() as $name) {
            foreach ($server->types() as $candidate) {
                if ($candidate->type() === $name) {
                    \array_push($relationNames, ...self::countableRelationNames($candidate));
                    break;
                }
            }
        }

        return [...$tokens, ...\array_values(\array_unique($relationNames))];
    }

    /**
     * The valid `withCount` tokens for a relationship (linkage) `GET …/relationships/{rel}`:
     * only `_self_` (when the relation is {@see RelationMetadataInterface::isCountable()}),
     * since the linkage endpoint renders identifiers, not the related resources' own
     * relationships.
     *
     * @return list<string>
     */
    public static function relationshipWithCountTokens(RelationMetadataInterface $relation): array
    {
        return $relation->isCountable() ? [CountableProfile::SELF_TOKEN] : [];
    }

    /**
     * Whether a relationship read pages its linkage: a to-many whose members are one type,
     * or stored pairs.
     */
    private static function pagesLinkage(RelationMetadataInterface $relation): bool
    {
        return $relation->isToMany()
            && (RelationFacts::monomorphicType($relation) !== null || RelationFacts::identifiesMembers($relation));
    }

    /**
     * The names of a type's countable to-many relations, in declaration order — the
     * relation tokens a `?withCount` accepts for that type.
     *
     * @return list<string>
     */
    private static function countableRelationNames(TypeMetadataInterface $type): array
    {
        $names = [];
        foreach ($type->relations() as $relation) {
            if ($relation->isCountable() && !RelationFacts::isWriteOnly($relation)) {
                $names[] = $relation->name();
            }
        }

        return $names;
    }
}
