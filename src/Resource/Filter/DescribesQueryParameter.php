<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\OpenApi\QueryParameterShape;
use haddowg\JsonApi\OpenApi\Schema;

/**
 * A {@see FilterInterface} whose `filter[<key>]` query parameter has a **structured**
 * wire shape — a nested object (`filter[<key>][min]`/`[max]`), a comma-list, or any
 * non-scalar serialization — and so describes its own OpenAPI parameter envelope
 * instead of being special-cased by the projector.
 *
 * The mapping the OpenAPI {@see \haddowg\JsonApi\OpenApi\OperationProjector} builds is
 * otherwise a closed switch on the built-in filter types; this is the extension point
 * that lets a filter (including a consumer-defined one) declare a non-scalar parameter
 * that documents correctly, exactly as
 * {@see \haddowg\JsonApi\Resource\Constraint\ProvidesJsonSchema} lets a constraint
 * self-describe its value keyword. A filter that does **not** implement this interface
 * is projected as a plain scalar whose value schema comes from its
 * {@see FilterInterface::constraints()}.
 *
 * **Contract.** {@see describeQueryParameter()} receives the value schema the projector
 * has already built from this filter's constraints and returns the full parameter shape
 * — typically **wrapping** that value schema (into an object's `min`/`max` properties,
 * or an array's `items`) and pairing it with the OAS `style`/`explode` for the wire
 * form. The schema shape stays framework-neutral (it is JSON Schema + an OAS style
 * discriminator), so — like a constraint's JSON Schema — it belongs on the filter, not
 * in an adapter.
 */
interface DescribesQueryParameter extends FilterInterface
{
    /**
     * The OpenAPI parameter shape for this filter's `filter[<key>]`, given the value
     * `$valueSchema` already projected from {@see FilterInterface::constraints()}.
     */
    public function describeQueryParameter(Schema $valueSchema): QueryParameterShape;
}
