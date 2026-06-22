<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The factory for a polymorphic relationship: the related resource may be one
 * of several declared types, passed as a non-empty list to {@see make()}. Each
 * related object's serializer is resolved at runtime from its own type. The
 * list is mandatory, so a polymorphic relationship can never be declared
 * without its candidate types.
 */
trait DeclaresPolymorphicTypes
{
    /**
     * @param non-empty-list<string> $types
     *
     * @return static
     */
    public static function make(string $name, array $types): static
    {
        return (new static($name))->withRelatedTypes(...$types);
    }
}
