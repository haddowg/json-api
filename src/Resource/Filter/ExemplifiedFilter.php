<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * A filter that carries an **example** value for the OpenAPI generator to surface on its
 * `filter[<key>]` query parameter.
 *
 * Implemented by every value-carrying filter through {@see ExposesValueMetadata}, so an
 * `->example(...)` declared on a filter builder reaches the generated document. The
 * generator reads it through an `instanceof ExemplifiedFilter` check, the same way it
 * reads {@see DescribedFilter}, so a consumer-defined filter opts in by implementing the
 * two methods.
 *
 * The example is the parameter's whole value, in the shape its schema describes: a scalar
 * for {@see Where}, a list for {@see WhereIn}, `['min' => …, 'max' => …]` for
 * {@see Range}. The generator emits it as declared and does not check it against the
 * schema.
 */
interface ExemplifiedFilter extends FilterInterface
{
    /**
     * Whether an example value was declared (distinct from a declared `null`).
     */
    public function hasExample(): bool;

    /**
     * The declared example value; only meaningful when {@see hasExample()} is true.
     */
    public function getExample(): mixed;
}
