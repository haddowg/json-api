<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A field whose value's shape can project as a named schema component, declared with
 * `component()` on its builder: {@see Obj}, {@see OneOf}, {@see Union}, {@see Map} and
 * {@see ArrayList}.
 */
interface NamedSchemaInterface extends FieldInterface
{
    /**
     * The component name the shape projects as, or `null` to project it inline.
     */
    public function componentName(): ?string;
}
