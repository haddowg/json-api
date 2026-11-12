<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresDeprecatedRelation;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresDetachmentRefusal;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresFixedMembership;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresIdentifiedMembers;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresIdentifierMeta;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresLinkageResolution;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresPolymorphicRelation;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresRelationDefaultSort;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresRelationExampleId;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresRelationUriFieldName;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresWriteOnlyRelation;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Resource\Constraint\MaxItems;
use haddowg\JsonApi\Resource\Constraint\MinItems;
use haddowg\JsonApi\Resource\Field\ConditionalFieldInterface;
use haddowg\JsonApi\Resource\Field\ConditionalRelationInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Reads the optional facts a relation's metadata may declare through the opt-in
 * `Declares*` interfaces, so the component, path and link projections agree on them.
 *
 * @internal
 */
final class RelationFacts
{
    /**
     * The segment the relation's related and relationship paths are keyed by.
     */
    public static function uriFieldName(RelationMetadataInterface $relation): string
    {
        return $relation instanceof DeclaresRelationUriFieldName ? $relation->uriFieldName() : $relation->name();
    }

    /**
     * Whether the relation is unconditionally write-only.
     */
    public static function isWriteOnly(RelationMetadataInterface $relation): bool
    {
        return $relation instanceof DeclaresWriteOnlyRelation && $relation->isWriteOnly();
    }

    /**
     * Whether a create may not set the relation: its relation field among the type's
     * fields is unconditionally read-only on create. A relation read-only only by a
     * request predicate stays settable, so the document describes the superset, as it
     * does for an attribute.
     */
    public static function isReadOnlyOnCreate(TypeMetadataInterface $type, RelationMetadataInterface $relation): bool
    {
        return self::field($type, $relation)?->isReadOnly(true) ?? false;
    }

    /**
     * The relation's definition among the type's fields, or `null` when the type declares
     * no field of that name.
     */
    public static function field(TypeMetadataInterface $type, RelationMetadataInterface $relation): ?RelationInterface
    {
        foreach ($type->fields() as $field) {
            if ($field instanceof RelationInterface && $field->name() === $relation->name()) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Whether the relation lets a relationship mutation in `$mode` through: never when its
     * metadata refuses the mode outright, conditionally when its definition gates the mode
     * on a predicate or when no definition says, and always otherwise.
     */
    public static function mutation(TypeMetadataInterface $type, RelationMetadataInterface $relation, Mode $mode): MutationPermission
    {
        $allows = match ($mode) {
            Mode::Replace => $relation->allowsReplace(),
            Mode::Add => $relation->allowsAdd(),
            Mode::Remove => $relation->allowsRemove(),
        };
        if (!$allows) {
            return MutationPermission::Never;
        }

        $field = self::field($type, $relation);

        return $field instanceof ConditionalRelationInterface && !$field->hasMutationPredicate($mode)
            ? MutationPermission::Allowed
            : MutationPermission::Conditional;
    }

    /**
     * Whether the data layer can refuse to detach one of the relation's members
     * ({@see DeclaresDetachmentRefusal}); `true` when the metadata does not say.
     */
    public static function canRefuseDetachment(RelationMetadataInterface $relation): bool
    {
        return !$relation instanceof DeclaresDetachmentRefusal || $relation->canRefuseDetachment();
    }

    /**
     * Whether linkage a create sends for the relation can name a resource the data layer
     * cannot find ({@see DeclaresLinkageResolution}); `true` when the metadata does not say.
     */
    public static function canMissLinkage(RelationMetadataInterface $relation): bool
    {
        return !$relation instanceof DeclaresLinkageResolution || $relation->canMissLinkage();
    }

    /**
     * Whether a read of the relation's related or relationship endpoint can find the
     * relation absent for the caller and answer `RELATIONSHIP_NOT_EXISTS`: its definition
     * hides it or makes it write-only by a predicate, a relationship read renders it only
     * when `fields[<type>]` names it, or no definition says.
     */
    public static function canBeAbsentFromRead(TypeMetadataInterface $type, RelationMetadataInterface $relation, bool $linkage): bool
    {
        $field = self::field($type, $relation);
        if (!$field instanceof ConditionalFieldInterface) {
            return true;
        }

        return $field->hasHiddenPredicate() || $field->hasWriteOnlyPredicate() || ($linkage && $field->isSparseByDefault());
    }

    /**
     * Whether an `include` path through the relation can be refused with
     * `INCLUSION_NOT_ALLOWED`: it cannot be included, its definition gates inclusion on a
     * predicate, or no definition says. A write-only relation is unknown to `include`, so
     * it never is.
     */
    public static function canRefuseInclusion(TypeMetadataInterface $type, RelationMetadataInterface $relation): bool
    {
        if (self::isWriteOnly($relation)) {
            return false;
        }
        if (!$relation->isIncludable()) {
            return true;
        }
        $field = self::field($type, $relation);

        return !$field instanceof ConditionalRelationInterface || $field->hasInclusionPredicate();
    }

    /**
     * Whether the relation is declared deprecated.
     */
    public static function isDeprecated(RelationMetadataInterface $relation): bool
    {
        return $relation instanceof DeclaresDeprecatedRelation && $relation->isDeprecated();
    }

    /**
     * Whether the relation is projected as a union of its related types: it lists more
     * than one, or its metadata declares it polymorphic however many it lists.
     */
    public static function isPolymorphic(RelationMetadataInterface $relation): bool
    {
        return \count($relation->relatedTypes()) > 1
            || ($relation instanceof DeclaresPolymorphicRelation && $relation->isPolymorphic());
    }

    /**
     * Whether the relation is a to-many whose members are stored `[type, id]` pairs, so its
     * relationship endpoint pages them.
     */
    public static function identifiesMembers(RelationMetadataInterface $relation): bool
    {
        return $relation->isToMany() && $relation instanceof DeclaresIdentifiedMembers && $relation->storesIdentifiers();
    }

    /**
     * Whether the relation is polymorphic by declaration with a single related type, the
     * one shape a count of its types would mistake for a monomorphic relation.
     */
    public static function isSingleTargetPolymorphic(RelationMetadataInterface $relation): bool
    {
        return \count($relation->relatedTypes()) === 1 && self::isPolymorphic($relation);
    }

    /**
     * The single related type of a monomorphic relation, or `null` for a polymorphic one
     * or one declaring no related types.
     */
    public static function monomorphicType(RelationMetadataInterface $relation): ?string
    {
        $types = $relation->relatedTypes();

        return \count($types) === 1 && !self::isPolymorphic($relation) ? $types[0] : null;
    }

    /**
     * The `minItems()` / `maxItems()` a to-many relation's field declares for a create or
     * update, read from the relation field of the same name among the type's fields. Only
     * a write setting the whole membership takes them: an addition or a removal carries a
     * delta.
     *
     * @return array{min: int|null, max: int|null}
     */
    public static function itemBounds(TypeMetadataInterface $type, RelationMetadataInterface $relation, bool $creating): array
    {
        $bounds = ['min' => null, 'max' => null];
        if (!$relation->isToMany()) {
            return $bounds;
        }

        foreach ($type->fields() as $field) {
            if (!$field instanceof RelationInterface || $field->name() !== $relation->name()) {
                continue;
            }
            foreach ($field->constraints() as $constraint) {
                if (!$constraint->context()->appliesTo($creating)) {
                    continue;
                }
                if ($constraint instanceof MinItems) {
                    $bounds['min'] = \max($bounds['min'] ?? 0, $constraint->value);
                } elseif ($constraint instanceof MaxItems) {
                    $bounds['max'] = $bounds['max'] === null ? $constraint->value : \min($bounds['max'], $constraint->value);
                }
            }
        }

        return $bounds;
    }

    /**
     * `$linkage` carrying the relation's {@see itemBounds()} for the write.
     */
    public static function withItemBounds(Schema $linkage, TypeMetadataInterface $type, RelationMetadataInterface $relation, bool $creating): Schema
    {
        ['min' => $min, 'max' => $max] = self::itemBounds($type, $relation, $creating);
        if ($min !== null) {
            $linkage = $linkage->withMinItems($min);
        }

        return $max === null ? $linkage : $linkage->withMaxItems($max);
    }

    /**
     * Whether the relation's linkage carries a typed `meta.pivot`, and so has separate
     * response and request shapes: a monomorphic relation declaring pivot fields, or a
     * polymorphic to-many whose members are identified by the link rows those fields
     * live on.
     */
    public static function hasPivot(RelationMetadataInterface $relation): bool
    {
        return $relation->pivotFields() !== [] && (self::monomorphicType($relation) !== null || self::identifiesMembers($relation));
    }

    /**
     * Whether the relation is a polymorphic to-many identified by stored pairs whose link
     * rows it filters, sorts or renders pivot values from, so its relationship endpoint
     * takes `filter` and `sort` parameters over them and its identifiers carry a typed
     * `meta.pivot`.
     */
    public static function queriesLinkRows(RelationMetadataInterface $relation): bool
    {
        return self::identifiesMembers($relation)
            && self::monomorphicType($relation) === null
            && ($relation->pivotFields() !== [] || $relation->filters() !== [] || $relation->sorts() !== []);
    }

    /**
     * The members the relation declares its identifier meta resolver adds to each linkage
     * identifier's `meta` ({@see DeclaresIdentifierMeta}), empty when it declares none.
     *
     * @return list<FieldInterface>
     */
    public static function identifierMetaFields(RelationMetadataInterface $relation): array
    {
        return $relation instanceof DeclaresIdentifierMeta ? $relation->identifierMetaFields() : [];
    }

    /**
     * Whether the relation's response linkage carries a typed identifier `meta` of its own
     * declared members.
     */
    public static function hasIdentifierMeta(RelationMetadataInterface $relation): bool
    {
        return self::identifierMetaFields($relation) !== [];
    }

    /**
     * The `sort` tokens of the relation's declared default order, a leading `-` marking a
     * descending key, or `[]` when it declares none
     * ({@see DeclaresRelationDefaultSort}).
     *
     * @return list<string>
     */
    public static function defaultSort(RelationMetadataInterface $relation): array
    {
        if (!$relation instanceof DeclaresRelationDefaultSort) {
            return [];
        }

        return \array_map(
            static fn(\haddowg\JsonApi\Resource\Sort\SortDirective $directive): string => ($directive->descending ? '-' : '') . $directive->sort->key(),
            $relation->defaultSort(),
        );
    }

    /**
     * The example of the relation's relationship object: `{data: {type, id}}` for a
     * to-one and `{data: [{type, id}]}` for a to-many, of its first related type. The id
     * is the one the operation examples show, that type's `Id` example, else the
     * relation's {@see DeclaresRelationExampleId::exampleId()}. `null` when neither gives
     * an id.
     *
     * @param array<string, TypeMetadataInterface> $byType
     *
     * @return array{data: array{type: string, id: string}|list<array{type: string, id: string}>}|null
     */
    public static function relationshipExample(RelationMetadataInterface $relation, array $byType): ?array
    {
        $type = $relation->relatedTypes()[0] ?? null;
        if ($type === null) {
            return null;
        }

        $related = $byType[$type] ?? null;
        $id = ($related === null ? null : ExampleDocumentBuilder::idExample($related))
            ?? ($relation instanceof DeclaresRelationExampleId ? $relation->exampleId() : null);
        if ($id === null || $id === '') {
            return null;
        }

        $identifier = ['type' => $type, 'id' => $id];

        return ['data' => $relation->isToMany() ? [$identifier] : $identifier];
    }

    /**
     * Whether the relation declares `cannotChangeMembership()`
     * ({@see DeclaresFixedMembership}).
     */
    public static function fixesMembership(RelationMetadataInterface $relation): bool
    {
        return $relation instanceof DeclaresFixedMembership && $relation->fixesMembership();
    }

    /**
     * Whether some relation of some type on `$server` declares `cannotChangeMembership()`.
     */
    public static function anyFixesMembership(ServerMetadataInterface $server): bool
    {
        foreach ($server->types() as $type) {
            foreach ($type->relations() as $relation) {
                if (self::fixesMembership($relation)) {
                    return true;
                }
            }
        }

        return false;
    }
}
