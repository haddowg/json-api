<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * The OpenAPI 3.1 Parameter `style` discriminator (how a parameter value is
 * serialized into the request). Only the styles the projector actually emits are
 * modelled: a structured {@see \haddowg\JsonApi\Resource\Filter\Range} filter's
 * `filter[<key>][min]`/`[max]` value renders as a `deepObject` parameter, the
 * standard OAS way to document a nested-object query parameter; the `sort` and
 * `include` parameters render as a `form` parameter with `explode: false` — the
 * standard OAS way to document a single parameter carrying a comma-delimited
 * list (JSON:API §4.4).
 *
 * `pipeDelimited` and `spaceDelimited` are the only other separators OAS names, and
 * a {@see \haddowg\JsonApi\Resource\Filter\WhereIn} can be built on either
 * ({@see \haddowg\JsonApi\Resource\Filter\WhereInBuilder::delimiter()}). Any other
 * separator has no OAS spelling, so a filter using one documents its value as the
 * single opaque string the client sends rather than as a list split the wrong way.
 */
enum ParameterStyle: string
{
    case DeepObject = 'deepObject';
    case Form = 'form';
    case PipeDelimited = 'pipeDelimited';
    case SpaceDelimited = 'spaceDelimited';
}
