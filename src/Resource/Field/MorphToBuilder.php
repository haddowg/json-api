<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The mutable **relation builder** for a polymorphic {@see MorphTo} to-one
 * relationship: the related resource may be one of several declared types, passed
 * as the mandatory list to {@see make()}. {@see build()} freezes it into a readonly
 * {@see MorphTo} value object.
 */
final class MorphToBuilder extends AbstractRelationBuilder
{
    use DeclaresPolymorphicTypes;

    /**
     * Eager by default: the morph id/type sit on the owning model, so resolving the
     * linkage identifier is free (no query). {@see AbstractRelationBuilder::$dataOnlyWhenLoaded}.
     */
    protected bool $dataOnlyWhenLoaded = false;

    public function build(): MorphTo
    {
        return new MorphTo($this->fieldState(), $this->relationState());
    }
}
