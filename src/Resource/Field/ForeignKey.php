<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Internal\RegisteredResource;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A relation's foreign key resolved against its related type: the member it names there
 * and the column a data layer compares with the parents' ids. A data layer reads a
 * relation declared with one as the related rows whose {@see $column} is one of the
 * parents' {@see parentKey()}s, whatever stores the parent, and never reads the
 * relation off the parent model.
 *
 * The member is either a stored field holding the parent's id or a to-one relation to
 * the parent's type, which may be a `MorphTo` declared with `identifiedByColumns()`
 * ({@see morphColumns()}). {@see \haddowg\JsonApi\Serializer\RelationForeignKeyValidator}
 * checks that at warm-up, so {@see of()} only resolves.
 */
final readonly class ForeignKey
{
    /**
     * @param string             $name   the member the relation names, a field or to-one relation of the related type
     * @param string             $column the member's storage name on the related model: its `column()`, else its name
     * @param ?RelationInterface $toOne  the to-one relation the name resolves to, or `null` for a field
     */
    public function __construct(
        public string $name,
        public string $column,
        public ?RelationInterface $toOne = null,
    ) {}

    /**
     * Whether the foreign key is a to-one relation of the related type, so its column
     * holds a reference to the parent rather than the parent's id itself.
     */
    public function isRelation(): bool
    {
        return $this->toOne !== null;
    }

    /**
     * The fields a polymorphic foreign key keeps its target in, when the foreign key is a
     * `MorphTo` declared with {@see MorphToBuilder::identifiedByColumns()}, or `null`
     * otherwise. A data layer reads such a relation as the related rows whose type field
     * holds one of {@see MorphColumns::storedValues()} for the parent's type and whose id
     * field holds one of the parents' keys, and a write sets both fields.
     */
    public function morphColumns(): ?MorphColumns
    {
        return $this->toOne instanceof MorphTo ? $this->toOne->identifierColumns() : null;
    }

    /**
     * The name of `$relation`'s foreign key, or `null` when it declares none.
     */
    public static function nameOf(RelationInterface $relation): ?string
    {
        return $relation instanceof ForeignKeyedRelationInterface ? $relation->foreignKey() : null;
    }

    /**
     * `$relation`'s foreign key resolved against its related type, or `null` when the
     * relation declares none, its related type has no resource, or the resource declares
     * no stored member of that name.
     */
    public static function of(RelationInterface $relation, SerializerResolverInterface $resolver): ?self
    {
        $name = self::nameOf($relation);
        $relatedType = $relation->relatedTypes()[0] ?? null;
        if ($name === null || $relatedType === null || !$resolver->hasSerializerFor($relatedType)) {
            return null;
        }

        $related = $resolver->serializerFor($relatedType);

        return $related instanceof AbstractResource ? self::in($name, $related) : null;
    }

    /**
     * The foreign key `$name` resolved against `$related`'s declared members, or `null`
     * when it declares no stored field or relation of that name.
     */
    public static function in(string $name, AbstractResource $related): ?self
    {
        foreach ($related->allFields() as $field) {
            if ($field->name() !== $name || $field instanceof Id || $field->relatedVia() !== null) {
                continue;
            }

            if ($field instanceof RelationInterface) {
                return new self($name, $field->column() ?? $field->name(), $field);
            }

            $column = $field->column();

            return $column === null ? null : new self($name, $column);
        }

        return null;
    }

    /**
     * The id `$parent` is stored under, which the foreign key holds: its wire id from
     * `$serializer`, decoded through the id encoder its resource declares, if any. A
     * serializer decorating a resource reaches it through its own resolver. A uuid-like
     * key renders in lower case, so one stored in another case is read off the parent as
     * it is stored.
     */
    public static function parentKey(SerializerInterface $serializer, object $parent): mixed
    {
        $wireId = $serializer->getId($parent);
        foreach (RegisteredResource::of($serializer, $serializer->getType($parent))?->allFields() ?? [] as $field) {
            if (!$field instanceof Id) {
                continue;
            }

            $encoder = $field->encoder();
            if ($encoder !== null) {
                return $encoder->decode($wireId) ?? $wireId;
            }

            $stored = Accessor::get($parent, $field->column() ?? $field->name());

            return \is_string($stored) && $stored !== $wireId && StoredIdForms::comparable($stored) === $wireId ? $stored : $wireId;
        }

        return $wireId;
    }
}
