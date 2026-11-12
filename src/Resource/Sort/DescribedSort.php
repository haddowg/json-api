<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort;

/**
 * A sort that carries a human-readable **description** for the OpenAPI generator to
 * surface on the `sort` parameter, the counterpart of
 * {@see \haddowg\JsonApi\Resource\Filter\DescribedFilter}. {@see SortByField} and
 * {@see SortFamily} implement it; a consumer-defined sort opts in by implementing it.
 */
interface DescribedSort extends SortInterface
{
    /**
     * The declared description, or `null` when none was declared.
     */
    public function getDescription(): ?string;
}
