<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Scope;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads the scopes the request's access token grants. Scope enforcement runs only when
 * one is configured.
 */
interface GrantedScopesProviderInterface
{
    /**
     * The granted scopes, or `null` when the request carries no scoped token, such as a
     * session login or a token type without scopes. A `null` grant skips enforcement for
     * the request; `[]` is a token that grants nothing.
     *
     * @return list<string>|null
     */
    public function granted(ServerRequestInterface $request): ?array;
}
