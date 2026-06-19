<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * The OpenAPI 3.1 Parameter `style` discriminator (how a parameter value is
 * serialized into the request). Only the styles the projector actually emits are
 * modelled: a structured {@see \haddowg\JsonApi\Resource\Filter\Range} filter's
 * `filter[<key>][min]`/`[max]` value renders as a `deepObject` parameter, the
 * standard OAS way to document a nested-object query parameter.
 */
enum ParameterStyle: string
{
    case DeepObject = 'deepObject';
}
