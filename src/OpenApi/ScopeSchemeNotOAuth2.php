<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * The security scheme operation scopes are listed under is not an `oauth2` scheme. The
 * scopes are still projected, as OpenAPI 3.1 allows for any scheme, but there are no
 * flows to describe them in.
 */
final readonly class ScopeSchemeNotOAuth2 implements \Stringable
{
    public function __construct(
        public string $scheme,
        public SecuritySchemeType $type,
    ) {}

    public function message(): string
    {
        return \sprintf('Operation scopes are listed under the "%s" security scheme, which is %s, not oauth2, so no flow describes them.', $this->scheme, $this->type->value);
    }

    public function __toString(): string
    {
        return $this->message();
    }
}
