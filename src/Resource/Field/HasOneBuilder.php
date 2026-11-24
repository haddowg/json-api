<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The mutable **relation builder** for a {@see HasOne} to-one relationship backed
 * by a foreign key on the **related** model. Identical authoring surface to
 * {@see BelongsToBuilder}; only the linkage default differs. {@see build()} freezes
 * it into a readonly {@see HasOne} value object.
 */
final class HasOneBuilder extends BelongsToBuilder
{
    /**
     * Lazy by default (overriding {@see BelongsToBuilder}'s eager default): the
     * foreign key sits on the *related* model, so resolving the linkage identifier
     * is a query — the same N+1 risk as a to-many.
     * {@see AbstractRelationBuilder::$dataOnlyWhenLoaded}.
     */
    protected bool $dataOnlyWhenLoaded = true;

    /**
     * Starts a to-one relation to `$type` held by the related model. A `$foreignKey`
     * names the related type's field or to-one relation that holds the parent's id, and
     * the relation then reads its target through it on every path.
     *
     * @return static
     */
    public static function make(string $name, string $type, ?string $foreignKey = null): static
    {
        return (new static($name))->withRelatedTypes($type)->readThroughForeignKey($foreignKey);
    }

    /**
     * Starts a to-one relation to `$type` read through `$morphTo`, the related type's
     * `MorphTo` pointing back at the parent. {@see MorphOne::make()} is the way to call it.
     *
     * @internal
     */
    public static function morphOne(string $name, string $type, string $morphTo): self
    {
        return (new self($name))->withRelatedTypes($type)->readThroughMorphTo($morphTo);
    }

    public function build(): HasOne
    {
        return new HasOne($this->fieldState(), $this->relationState());
    }
}
