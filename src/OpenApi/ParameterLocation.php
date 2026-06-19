<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * The OpenAPI 3.1 Parameter `in` discriminator (where the parameter is carried).
 */
enum ParameterLocation: string
{
    case Query = 'query';
    case Header = 'header';
    case Path = 'path';
    case Cookie = 'cookie';
}
