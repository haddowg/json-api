<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The `deserializeUsing()` declaration of the set filter builders, {@see WhereInBuilder}
 * and {@see WhereNotInBuilder}.
 */
trait BuildsListDeserializer
{
    /**
     * @var \Closure(list<mixed>): list<mixed>|null
     */
    private ?\Closure $deserialize = null;

    /**
     * Maps the requested list to the list the column is compared against. The closure
     * receives every requested member at once, after the list is read and each member
     * validated, so value constraints, `enum()` and the OpenAPI document keep describing
     * the values a client sends. An empty returned list matches no row for `WhereIn` and
     * every row for `WhereNotIn`.
     *
     * @template TValue
     *
     * @param \Closure(list<TValue>): list<mixed> $deserialize
     */
    public function deserializeUsing(\Closure $deserialize): static
    {
        /** @var \Closure(list<mixed>): list<mixed> $deserialize */
        $this->deserialize = $deserialize;

        return $this;
    }
}
