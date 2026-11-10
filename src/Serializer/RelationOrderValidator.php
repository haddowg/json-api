<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DefaultSortedRelationInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\PositionedRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Resource\Sort\RelationSorts;
use haddowg\JsonApi\Resource\Sort\SortFamilyInterface;
use haddowg\JsonApi\Resource\Sort\SortMatcher;

/**
 * Validates how a resource type's to-many relations order their members **at boot /
 * container warm-up**, so a mistake fails at `cache:clear` or deploy instead of as a
 * 500 on the first request that reads the relation.
 *
 * A relation's {@see DefaultSortedRelationInterface::defaultSort()} keys must each
 * name a sort its vocabulary ({@see RelationSorts::vocabulary()}) accepts, and that
 * vocabulary includes the related type's sorts, which only a resolver can reach. A
 * relation builder rejects what it can see alone when it builds; this checks the rest.
 *
 * A `HasMany` positioned with `positionedBy()` stores each position on the related type,
 * so its field must be a stored `Integer` there, declared read-only, since clients never
 * set a position. A pivot relation's position field is checked when it builds.
 * A host invokes it once per registered resource, beside the
 * {@see EagerLoadValidator}.
 */
final class RelationOrderValidator
{
    public function __construct(private readonly SerializerResolverInterface $resolver) {}

    /**
     * Validates every relation `$serializer` declares for the resource `$type`. A
     * serializer that is not an {@see AbstractResource} declares no relations here and
     * is a no-op.
     *
     * @throws \LogicException when a default sort key names no sort the relation accepts,
     *                         or a related type's position field is missing, computed,
     *                         not an `Integer` or writable
     */
    public function validate(string $type, SerializerInterface $serializer): void
    {
        if (!$serializer instanceof AbstractResource) {
            return;
        }

        foreach ($serializer->allFields() as $field) {
            if ($field instanceof RelationInterface) {
                $this->validateDefaultSort($type, $field);
                $this->validatePosition($type, $field);
            }
        }
    }

    private function validateDefaultSort(string $type, RelationInterface $relation): void
    {
        if (!$relation instanceof DefaultSortedRelationInterface || $relation->defaultSort() === []) {
            return;
        }

        $vocabulary = RelationSorts::vocabulary($relation, $this->resolver);
        foreach ($relation->defaultSort() as $directive) {
            $key = $directive->sort->key();
            if (SortMatcher::recognises($vocabulary, $key)) {
                continue;
            }

            $accepted = \array_map(
                static fn(\haddowg\JsonApi\Resource\Sort\SortInterface $sort): string => $sort instanceof SortFamilyInterface ? $sort->key() . '.*' : $sort->key(),
                $vocabulary,
            );

            throw new \LogicException(\sprintf(
                'Relation "%s" on resource type "%s" declares a defaultSort() on "%s", which is not a sort the relation accepts. %s',
                $relation->name(),
                $type,
                $key,
                $accepted === [] ? 'It accepts no sorts.' : 'It accepts: ' . \implode(', ', $accepted) . '.',
            ));
        }
    }

    private function validatePosition(string $type, RelationInterface $relation): void
    {
        $position = $relation instanceof PositionedRelationInterface ? $relation->positionField() : null;
        if ($position === null || $relation instanceof PivotRelationInterface) {
            return;
        }

        $related = $relation->relatedTypes()[0] ?? null;
        if ($related === null || !$this->resolver->hasSerializerFor($related) || !$this->resolver->serializerFor($related) instanceof AbstractResource) {
            return;
        }

        $field = RelationSorts::relatedField($relation, $this->resolver, $position);
        $problem = match (true) {
            $field === null => 'it declares no such field',
            !$field instanceof Integer => 'it is not an Integer field',
            $field->column() === null => 'it is computed, not stored',
            !$field->isReadOnly(true) || !$field->isReadOnly(false) => 'it is writable; declare it ->readOnly(), since the server assigns positions',
            default => null,
        };

        if ($problem !== null) {
            throw new \LogicException(\sprintf(
                'Relation "%s" on resource type "%s" is positioned by "%s" on type "%s", but %s. A position is a stored, read-only Integer field of the related type.',
                $relation->name(),
                $type,
                $position,
                $related,
                $problem,
            ));
        }
    }
}
