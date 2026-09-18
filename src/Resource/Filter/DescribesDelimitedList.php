<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\OpenApi\ParameterStyle;
use haddowg\JsonApi\OpenApi\QueryParameterShape;
use haddowg\JsonApi\OpenApi\Schema;

/**
 * The {@see DescribesQueryParameter} implementation shared by the set filters
 * ({@see WhereIn}, {@see WhereNotIn}, {@see WhereIdIn}, {@see WhereIdNotIn}): their
 * wire value is a **list**, so the parameter is an array whose `items` carry the
 * value schema, not a bare scalar. The projected schema says "a list" whether or not
 * the author declared any value constraints — the container shape is a property of
 * the filter kind, and only the element type comes from the constraints (or, absent
 * those, from the field the filter targets).
 *
 * The declared {@see $delimiter} chooses the OAS style. Comma, pipe and space are
 * the three separators OAS can spell; a filter built on any other one documents as a
 * plain string, because an array paired with the wrong separator would tell a client
 * to send a request the server splits into the wrong values.
 *
 * @property-read ?string $delimiter
 */
trait DescribesDelimitedList
{
    public function describeQueryParameter(Schema $valueSchema): QueryParameterShape
    {
        $style = match ($this->delimiter) {
            null, '', ',' => ParameterStyle::Form,
            '|' => ParameterStyle::PipeDelimited,
            ' ' => ParameterStyle::SpaceDelimited,
            default => null,
        };

        if ($style === null) {
            return new QueryParameterShape(Schema::ofType('string'));
        }

        return new QueryParameterShape(
            Schema::ofType('array')->withItems($valueSchema),
            $style,
            false,
        );
    }
}
