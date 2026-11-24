<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\AbstractRelationValue;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\ForeignKey;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;

/**
 * Validates the foreign keys a resource type's relations read through **at boot /
 * container warm-up**, so a misnamed key fails at `cache:clear` or deploy instead of as
 * a 500 on the first read of the relation.
 *
 * A relation declared with a foreign key (`HasMany::make('notes', 'notes', 'author')`)
 * must name a member of its related type that holds the parent's id: a stored field, or
 * a to-one relation whose related type is the parent's type, which may be a `MorphTo`
 * declared with `identifiedByColumns()`. A field's values can't be checked against the
 * parent's type, so a field passes once it exists and is stored, unless it is one of the
 * fields such a `MorphTo` keeps its target in: its id field holds the ids of several
 * types, so the relation must name the `MorphTo` to compare the type too. A
 * related type the resolver doesn't serve through an {@see AbstractResource} declares
 * no members to check, and passes.
 *
 * A host invokes it once per registered resource, beside the {@see RelationOrderValidator}.
 */
final class RelationForeignKeyValidator
{
    public function __construct(private readonly SerializerResolverInterface $resolver) {}

    /**
     * Validates every relation `$serializer` declares for the resource `$type`. A
     * serializer that is not an {@see AbstractResource} declares no relations here and
     * is a no-op.
     *
     * @throws \LogicException when a foreign key names no stored field or to-one relation
     *                         of the related type, a to-one that relates to another type, or
     *                         a field a `MorphTo` keeps its target in
     */
    public function validate(string $type, SerializerInterface $serializer): void
    {
        if (!$serializer instanceof AbstractResource) {
            return;
        }

        foreach ($serializer->allFields() as $field) {
            if ($field instanceof RelationInterface) {
                $this->validateRelation($type, $field);
            }
        }
    }

    /**
     * The name of the `MorphTo` of `$related` declared with `identifiedByColumns()` whose
     * id or type field is the stored field `$member`, or `null` when there is none.
     */
    private static function morphHoldingId(AbstractResource $related, FieldInterface $member): ?string
    {
        if ($member instanceof RelationInterface) {
            return null;
        }

        $stored = [$member->name(), $member->column()];
        foreach ($related->allFields() as $field) {
            $columns = $field instanceof MorphTo ? $field->identifierColumns() : null;
            if ($columns !== null && (\in_array($columns->id, $stored, true) || \in_array($columns->type, $stored, true))) {
                return $field->name();
            }
        }

        return null;
    }

    private function validateRelation(string $type, RelationInterface $relation): void
    {
        $name = ForeignKey::nameOf($relation);
        $relatedType = $relation->relatedTypes()[0] ?? null;
        if ($name === null || $relatedType === null || !$this->resolver->hasSerializerFor($relatedType)) {
            return;
        }

        $related = $this->resolver->serializerFor($relatedType);
        if (!$related instanceof AbstractResource) {
            return;
        }

        $member = null;
        foreach ($related->allFields() as $field) {
            if ($field->name() === $name) {
                $member = $field;

                break;
            }
        }

        $problem = match (true) {
            $member === null => 'it declares no such field or relation',
            $member instanceof Id => 'it is the related type\'s id',
            $relation instanceof AbstractRelationValue && $relation->foreignKeyIsMorph() && !$member instanceof MorphTo => 'it is not a MorphTo, which a MorphMany or MorphOne must name',
            $member->relatedVia() !== null => 'it is flattened from another relation with on(), so the related row doesn\'t store it',
            $member instanceof RelationInterface && $member->isToMany() => 'it is a to-many relation; a foreign key is a field or a to-one relation',
            $member instanceof RelationInterface && !\in_array($type, $member->relatedTypes(), true) => \sprintf(
                'it relates to %s, not to "%s"',
                \implode(', ', \array_map(static fn(string $target): string => '"' . $target . '"', $member->relatedTypes())),
                $type,
            ),
            !$member instanceof RelationInterface && $member->column() === null => 'it is computed, not stored',
            ($morph = self::morphHoldingId($related, $member)) !== null => \sprintf(
                'it is a field the polymorphic relation "%1$s" stores its target in, whose ids may belong to any of several types, so rows pointing at another type with the same id would be read too. Name the MorphTo "%1$s" as the foreign key instead',
                $morph,
            ),
            default => null,
        };

        if ($problem !== null) {
            throw new \LogicException(\sprintf(
                'Relation "%s" on resource type "%s" reads through the foreign key "%s" on type "%s", but %s. A foreign key is a stored field of the related type holding the parent\'s id, or a to-one relation of the related type to "%s".',
                $relation->name(),
                $type,
                $name,
                $relatedType,
                $problem,
                $type,
            ));
        }
    }
}
