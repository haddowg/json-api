<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The factory for a monomorphic relationship: every such relation relates to
 * exactly one resource type, declared as the mandatory second argument to
 * {@see make()}. The type is a required parameter, so a relationship can never
 * be declared without one — omitting it is a compile-time error.
 */
trait DeclaresMonomorphicType
{
    /**
     * @return static
     */
    public static function make(string $name, string $type): static
    {
        return (new static($name))->withRelatedTypes($type);
    }
}
