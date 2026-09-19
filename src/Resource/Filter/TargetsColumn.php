<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Resource\Field\FieldInterface;

/**
 * A {@see FilterInterface} that compares **one backing column** of the resource it
 * filters — `Where`, `WhereIn`/`WhereNotIn`, `WhereIdIn`/`WhereIdNotIn`, `Range`.
 * The column names storage, not a JSON:API member, so this is not a field name; it
 * is the same string a {@see FieldInterface::column()} carries.
 *
 * The OpenAPI projection reads it to find the field a filter targets and default
 * the `filter[<key>]` value schema to that field's JSON type when the author
 * declared no value constraints — a filter over an `Integer` documents as an
 * integer rather than as the bare `string` every query parameter already is.
 * Matching is exact against the type's own field inventory: a column no single
 * field claims resolves to nothing, and the value falls back to `string`
 * (ADR 0138).
 *
 * A filter that compares something else — a relationship path
 * ({@see WhereThrough}), a relationship's existence ({@see WhereHas}), several
 * columns at once ({@see WhereGroup}) — does not implement this. Nor does a filter
 * whose value is ignored ({@see WhereNull}): there is no compared value to type.
 */
interface TargetsColumn extends FilterInterface
{
    /**
     * The backing column whose value this filter compares.
     */
    public function targetColumn(): string;
}
