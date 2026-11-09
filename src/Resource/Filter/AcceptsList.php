<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * A {@see FilterInterface} that can declare its value a list, through the builder's
 * `acceptsList()` or, on a custom filter, by returning a {@see ListValue}.
 *
 * A declared list changes three things. Adapters read the value through
 * {@see ListValue::values()}, so the array form `filter[<key>][]=a&filter[<key>][]=b`
 * always works and the comma form only while the list is delimited, and every member is
 * validated on its own. The OpenAPI document adds a `filter[<key>][]` parameter for the
 * array form, keeping `filter[<key>]` for the delimited one. And a filter family's
 * resolved filters must declare the same list the family does.
 */
interface AcceptsList extends FilterInterface
{
    /**
     * How the value is read as a list, or `null` when the filter declares none.
     */
    public function listValue(): ?ListValue;
}
