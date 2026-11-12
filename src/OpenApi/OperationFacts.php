<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ActionCreated;
use haddowg\JsonApi\OpenApi\Metadata\ActionDeclarations;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresDroppedFilterDefaults;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Resource\Filter\FilterMatcher;
use haddowg\JsonApi\Resource\Filter\HasRemovableDefault;
use haddowg\JsonApi\Resource\Sort\SortMatcher;
use haddowg\JsonApi\Schema\Profile\CountableProfile;

/**
 * What one enumerated operation takes: the `page` schema it advertises, the `withCount`
 * tokens it accepts on a collection or on the one resource it renders and whether it reads a request document. The operation projector and the
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
            OperationKind::FetchOne => self::resourceWithCountTokens($type),
            OperationKind::Create => self::declaresStatus($type->responsesFor(OperationType::Create), 201) ? self::resourceWithCountTokens($type) : [],
            OperationKind::Update => self::declaresStatus($type->responsesFor(OperationType::Update), 200) ? self::resourceWithCountTokens($type) : [],
            OperationKind::FetchRelated => match (true) {
                $relation === null => [],
                $relation->isToMany() => self::relatedWithCountTokens($relation, $server),
                default => self::toOneRelatedWithCountTokens($relation, $server),
            },
            OperationKind::FetchRelationship => $relation !== null && self::pagesLinkage($relation) ? self::relationshipWithCountTokens($relation) : [],
            OperationKind::Action => $operation->action === null ? [] : self::actionWithCountTokens($operation->action, $server),
            default => [],
        };
    }

    /**
     * The valid `withCount` tokens for an operation rendering one `$type` resource: a
     * fetch-one, or a create or update answering with the resource. Each countable relation
     * of the type counts on it; `_self_` names a collection's total, so a single resource
     * takes none.
     *
     * @return list<string>
     */
    public static function resourceWithCountTokens(TypeMetadataInterface $type): array
    {
        return self::countableRelationNames($type);
    }

    /**
     * The valid `withCount` tokens for a to-one related `GET /{type}/{id}/{rel}`: every
     * countable relation of the related type, counted on the related resource. A
     * polymorphic to-one takes a relation any of its related types counts. There is no
     * collection, so no `_self_`.
     *
     * @return list<string>
     */
    public static function toOneRelatedWithCountTokens(RelationMetadataInterface $relation, ServerMetadataInterface $server): array
    {
        $names = [];
        foreach ($relation->relatedTypes() as $name) {
            $related = self::typeNamed($server, $name);
            if ($related !== null) {
                \array_push($names, ...self::countableRelationNames($related));
            }
        }

        return \array_values(\array_unique($names));
    }

    /**
     * The valid `withCount` tokens for an action answering with one resource of a type
     * ({@see ActionResource} or {@see ActionCreated}): that type's countable relations. An
     * action answering any other way renders no resource to count on.
     *
     * @return list<string>
     */
    public static function actionWithCountTokens(ActionMetadataInterface $action, ServerMetadataInterface $server): array
    {
        foreach ($action->responds() as $response) {
            if ($response instanceof ActionResource || $response instanceof ActionCreated) {
                $body = self::typeNamed($server, $response->bodyType());

                return $body === null ? [] : self::resourceWithCountTokens($body);
            }
        }

        return [];
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

        return [...$tokens, ...self::toOneRelatedWithCountTokens($relation, $server)];
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
     * Whether a declared success-response set advertises `$status`, the status whose body is
     * the type's own resource document.
     *
     * @param list<OperationResponseInterface> $responses
     */
    public static function declaresStatus(array $responses, int $status): bool
    {
        foreach ($responses as $response) {
            if ($response->status() === $status) {
                return true;
            }
        }

        return false;
    }

    /**
     * The sort vocabulary the operation checks a `sort` against, or `null` when it ignores
     * `sort`. A collection read takes its type's sorts, and a to-many's related and
     * relationship reads the merged related ones ({@see relatedSortVocabulary()}). A
     * polymorphic to-many not stored as pairs shares no related type, so it has none and any
     * sort is refused there. So does a to-many relationship mutation, whose echoed linkage an
     * adapter may read through a collection query.
     *
     * @return list<\haddowg\JsonApi\Resource\Sort\SortInterface>|null
     */
    public static function sortVocabulary(TypeMetadataInterface $type, EnumeratedOperation $operation, ServerMetadataInterface $server): ?array
    {
        $relation = $operation->relation;

        return match ($operation->key->kind) {
            OperationKind::FetchCollection => $type->sorts(),
            OperationKind::FetchRelated, OperationKind::FetchRelationship => match (true) {
                $relation === null || !$relation->isToMany() => null,
                self::pagesLinkage($relation) => self::relatedSortVocabulary($relation, $server),
                default => [],
            },
            OperationKind::UpdateRelationship, OperationKind::AddRelationship, OperationKind::RemoveRelationship => $relation !== null && $relation->isToMany() ? [] : null,
            default => null,
        };
    }

    /**
     * The filter vocabulary the operation checks a `filter` against, or `null` when it
     * ignores `filter`. A related or relationship read takes the merged related vocabulary
     * ({@see relatedFilterVocabulary()}), or none for a polymorphic relation; a collection
     * action takes the filters it declares; a to-many relationship mutation, like its
     * {@see sortVocabulary()}, takes none.
     *
     * @return list<\haddowg\JsonApi\Resource\Filter\FilterInterface>|null
     */
    public static function filterVocabulary(TypeMetadataInterface $type, EnumeratedOperation $operation, ServerMetadataInterface $server): ?array
    {
        $relation = $operation->relation;

        return match ($operation->key->kind) {
            OperationKind::FetchCollection => $type->filters(),
            OperationKind::FetchRelated, OperationKind::FetchRelationship => match (true) {
                $relation === null => null,
                RelationFacts::monomorphicType($relation) !== null,
                $relation->isToMany() && ($operation->key->kind === OperationKind::FetchRelated || RelationFacts::identifiesMembers($relation)) => self::relatedFilterVocabulary($relation, $server),
                default => [],
            },
            OperationKind::UpdateRelationship, OperationKind::AddRelationship, OperationKind::RemoveRelationship => $relation !== null && $relation->isToMany() ? [] : null,
            OperationKind::Action => $operation->action !== null && ActionDeclarations::filters($type, $operation->action) !== []
                ? ActionDeclarations::filters($type, $operation->action)
                : null,
            default => null,
        };
    }

    /**
     * The filter vocabulary a related / relationship endpoint honours: the **related
     * resource's own** filters merged with the relation's relation-scoped
     * ({@see RelationMetadataInterface::filters()}) ones — the relation wins on a key
     * collision, mirroring the runtime's merged criteria. A related filter whose default
     * the relation drops ({@see DeclaresDroppedFilterDefaults}) is taken without it. A
     * polymorphic relation has no shared vocabulary, so only its own (typically empty)
     * filters apply.
     *
     * @return list<\haddowg\JsonApi\Resource\Filter\FilterInterface>
     */
    public static function relatedFilterVocabulary(RelationMetadataInterface $relation, ServerMetadataInterface $server): array
    {
        $related = self::relatedType($relation, $server)?->filters() ?? [];
        if ($relation instanceof DeclaresDroppedFilterDefaults) {
            $related = \array_map(
                static fn(\haddowg\JsonApi\Resource\Filter\FilterInterface $filter): \haddowg\JsonApi\Resource\Filter\FilterInterface => $filter instanceof HasRemovableDefault
                    && $filter->hasDefault()
                    && ($relation->dropsAllFilterDefaults() || \in_array($filter->key(), $relation->droppedFilterDefaults(), true))
                    ? $filter->withoutDefault()
                    : $filter,
                $related,
            );
        }

        return FilterMatcher::merge($related, $relation->filters());
    }

    /**
     * The sort vocabulary a related to-many endpoint honours (the related resource's
     * own sorts merged with the relation's), mirroring {@see relatedFilterVocabulary()}.
     *
     * @return list<\haddowg\JsonApi\Resource\Sort\SortInterface>
     */
    public static function relatedSortVocabulary(RelationMetadataInterface $relation, ServerMetadataInterface $server): array
    {
        return SortMatcher::merge(self::relatedType($relation, $server)?->sorts() ?? [], $relation->sorts());
    }

    /**
     * The metadata of a monomorphic relation's related type, or `null` for a polymorphic
     * relation or a type the server does not describe.
     */
    public static function relatedType(RelationMetadataInterface $relation, ServerMetadataInterface $server): ?TypeMetadataInterface
    {
        $single = RelationFacts::monomorphicType($relation);

        return $single !== null ? self::typeNamed($server, $single) : null;
    }

    /**
     * Whether the operation checks `?include` before it runs: a collection or single read,
     * a create, an update, or a related read. The scope check of each `include` hop runs on
     * the same operations.
     */
    public static function validatesInclude(EnumeratedOperation $operation): bool
    {
        return match ($operation->key->kind) {
            OperationKind::FetchCollection, OperationKind::FetchOne, OperationKind::Create, OperationKind::Update, OperationKind::FetchRelated => true,
            default => false,
        };
    }

    /**
     * The types whose resources the operation renders as primary data, `[]` when it
     * renders none: the type itself on a read, and on a create or update answering with
     * its resource; the related types on a related read; an action's response type when it
     * answers with a resource.
     *
     * @return list<TypeMetadataInterface>
     */
    public static function renderedTypes(TypeMetadataInterface $type, EnumeratedOperation $operation, ServerMetadataInterface $server): array
    {
        $types = match ($operation->key->kind) {
            OperationKind::FetchCollection, OperationKind::FetchOne => [$type],
            OperationKind::Create => self::declaresStatus($type->responsesFor(OperationType::Create), 201) ? [$type] : [],
            OperationKind::Update => self::declaresStatus($type->responsesFor(OperationType::Update), 200) ? [$type] : [],
            OperationKind::FetchRelated => \array_map(
                static fn(string $name): ?TypeMetadataInterface => self::typeNamed($server, $name),
                $operation->relation?->relatedTypes() ?? [],
            ),
            OperationKind::Action => [$operation->action === null ? null : self::actionBodyType($operation->action, $server)],
            default => [],
        };

        return \array_values(\array_filter($types, static fn(?TypeMetadataInterface $rendered): bool => $rendered !== null));
    }

    /**
     * Whether the operation renders a document, resource or linkage, whose root the
     * render-time `include` and `withCount` checks run against: every operation rendering
     * resources ({@see renderedTypes()}), a relationship read, and a relationship mutation
     * echoing the linkage (a write-only relation's answers `204`).
     */
    public static function rendersDocument(TypeMetadataInterface $type, EnumeratedOperation $operation, ServerMetadataInterface $server): bool
    {
        return match ($operation->key->kind) {
            OperationKind::FetchRelationship => true,
            OperationKind::UpdateRelationship, OperationKind::AddRelationship, OperationKind::RemoveRelationship => $operation->relation !== null && !RelationFacts::isWriteOnly($operation->relation),
            default => self::renderedTypes($type, $operation, $server) !== [],
        };
    }

    /**
     * Whether the operation reads `relatedQuery` under the Relationship Queries profile:
     * one rendering resources of a type that declares a relation to address, as its
     * `relatedQuery` parameter is offered on the primary reads. The profile's registration
     * is the caller's to check.
     */
    public static function readsRelatedQuery(TypeMetadataInterface $type, EnumeratedOperation $operation, ServerMetadataInterface $server): bool
    {
        foreach (self::renderedTypes($type, $operation, $server) as $rendered) {
            if ($rendered->relations() !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The type a resource-hydrating operation ({@see hydratesResource()}) hydrates: its
     * own, or an action's input type. `null` for any other operation, or an input type the
     * server does not describe.
     */
    public static function hydratedType(TypeMetadataInterface $type, EnumeratedOperation $operation, ServerMetadataInterface $server): ?TypeMetadataInterface
    {
        if (!self::hydratesResource($operation)) {
            return null;
        }

        return $operation->action === null ? $type : self::typeNamed($server, $operation->action->inputType() ?? $type->type());
    }

    /**
     * The type an action answers with one resource of ({@see ActionResource} or
     * {@see ActionCreated}), or `null`.
     */
    private static function actionBodyType(ActionMetadataInterface $action, ServerMetadataInterface $server): ?TypeMetadataInterface
    {
        foreach ($action->responds() as $response) {
            if ($response instanceof ActionResource || $response instanceof ActionCreated) {
                return self::typeNamed($server, $response->bodyType());
            }
        }

        return null;
    }

    private static function typeNamed(ServerMetadataInterface $server, string $name): ?TypeMetadataInterface
    {
        foreach ($server->types() as $type) {
            if ($type->type() === $name) {
                return $type;
            }
        }

        return null;
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
