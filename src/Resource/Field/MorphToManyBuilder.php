<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The mutable **relation builder** for a polymorphic {@see MorphToMany} to-many
 * relationship: the collection's members may each be one of several declared types,
 * passed as the mandatory list to {@see make()}. {@see build()} freezes it into a
 * readonly {@see MorphToMany} value object.
 */
final class MorphToManyBuilder extends AbstractRelationBuilder
{
    use DeclaresPolymorphicTypes;

    public function build(): MorphToMany
    {
        return new MorphToMany($this->fieldState(), $this->relationState());
    }
}
