<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * A filter that carries a human-readable **description** for the OpenAPI generator
 * to surface on its `filter[<key>]` query parameter.
 *
 * Implemented by every value-carrying filter through the {@see HasValueConstraints}
 * trait (which already defines {@see getDescription()}), so the convenience
 * filters' preset descriptions ("Matches values containing the given substring.",
 * "Matches values within the given inclusive numeric range…") light up in the
 * generated document. The generator reads this via an `instanceof DescribedFilter`
 * check rather than reaching into the trait, so the access stays type-safe over the
 * bare {@see FilterInterface}.
 */
interface DescribedFilter extends FilterInterface
{
    /**
     * The declared description surfaced by the OpenAPI generator, or `null` when
     * none was declared (the generator falls back to a generic default).
     */
    public function getDescription(): ?string;
}
