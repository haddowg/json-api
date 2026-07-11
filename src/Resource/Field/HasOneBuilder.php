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

    public function build(): HasOne
    {
        return new HasOne($this->fieldState(), $this->relationState());
    }
}
