<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * The OpenAPI 3.1 Security Scheme `type` discriminator.
 */
enum SecuritySchemeType: string
{
    case ApiKey = 'apiKey';
    case Http = 'http';
    case MutualTls = 'mutualTLS';
    case OAuth2 = 'oauth2';
    case OpenIdConnect = 'openIdConnect';
}
