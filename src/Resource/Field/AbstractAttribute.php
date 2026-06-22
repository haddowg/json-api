<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * Convenience base for non-relationship fields (attributes). Adds the
 * single-argument {@see make()} factory: an attribute is identified by its
 * member name alone.
 *
 * Relationships extend {@see AbstractRelation} instead — which deliberately
 * does *not* inherit this factory, because a relationship is incomplete
 * without its related resource type. Each relation family declares its own
 * {@see make()} requiring the type as a mandatory second argument (a single
 * type for a monomorphic relation, a list for a polymorphic one), so a
 * relationship can never be declared without one.
 */
abstract class AbstractAttribute extends AbstractField
{
    /**
     * @return static
     */
    public static function make(string $name): static
    {
        return new static($name);
    }
}
