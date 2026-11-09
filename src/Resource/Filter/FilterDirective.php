<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * One requested `filter[<key>]`: the declared {@see FilterInterface} the key matched,
 * a filter family's already resolved to its concrete filter, and the value to apply. A
 * filter that {@see AcceptsList accepts a list} carries its value as the list of members
 * {@see ListValue::values()} read; every other filter carries the value as requested.
 */
final readonly class FilterDirective
{
    public function __construct(
        public FilterInterface $filter,
        public mixed $value,
    ) {}

    /**
     * The requested key, which is the matched filter's key.
     */
    public function key(): string
    {
        return $this->filter->key();
    }
}
