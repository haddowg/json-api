<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * How a filter's `filter[<key>]` query parameter is shaped in the OpenAPI
 * document — the parts a filter controls, returned from
 * {@see \haddowg\JsonApi\Resource\Filter\DescribesQueryParameter::describeQueryParameter()}.
 *
 * A plain scalar filter never constructs one (the projector defaults to its
 * constraint-derived value {@see Schema} with no style). A structured filter
 * returns the wrapped value schema plus the OAS `style`/`explode` that documents
 * its wire serialization — a {@see \haddowg\JsonApi\Resource\Filter\Range}'s nested
 * `filter[<key>][min]`/`[max]` renders as `style: deepObject, explode: true`; a
 * comma-list value renders as `style: form, explode: false`.
 *
 * `$explode` mirrors {@see Parameter}: `null` omits the keyword (a scalar), and a
 * non-null value emits `explode: true|false` alongside a set `$style`.
 */
final readonly class QueryParameterShape
{
    public function __construct(
        public Schema $schema,
        public ?ParameterStyle $style = null,
        public ?bool $explode = null,
    ) {}
}
