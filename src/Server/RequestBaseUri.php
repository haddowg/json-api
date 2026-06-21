<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Server;

use Psr\Http\Message\UriInterface;

/**
 * Resolves the absolute base URI that every generated link is prefixed with for a
 * single response.
 *
 * The model has two cases, keyed on whether the server has a configured base URI
 * ({@see ServerInterface::baseUri()}):
 *
 * - **Configured (non-empty):** that fixed base pins a canonical host and is used
 *   verbatim, but trailing-slash tolerant — it is `rtrim`'d of `/` before being
 *   prepended, so `https://host/` and `/api/` never produce a double slash against
 *   the leading-slash paths the link builders emit.
 * - **Empty (the default):** the base is derived from the request itself — the
 *   origin of the request URI, `<scheme>://<authority>` (authority = host, with
 *   port and userinfo when present). So an empty base yields request-absolute links
 *   (e.g. `https://music.example/albums/1` when the request `Host` is
 *   `music.example`), not host-relative ones. The request URI is already
 *   proxy/forwarded-host-aware upstream; this just reads it.
 *
 * A request with no resolvable origin (no authority, or no scheme to pair with it)
 * degrades to the empty string, so a malformed or relative request URI yields a
 * host-relative link rather than a broken `://host` prefix.
 *
 * @see https://jsonapi.org/format/1.1/#document-links
 */
final class RequestBaseUri
{
    /**
     * Resolves the base URI to prepend to generated links: the configured base
     * (trailing-slash trimmed) when one is set, otherwise the request URI's origin.
     */
    public static function resolve(string $configuredBaseUri, UriInterface $requestUri): string
    {
        if ($configuredBaseUri !== '') {
            return \rtrim($configuredBaseUri, '/');
        }

        return self::originOf($requestUri);
    }

    /**
     * The `<scheme>://<authority>` origin of a request URI, or the empty string
     * when it has no authority (and so no origin to derive) or no scheme to pair
     * with the authority — leaving links host-relative rather than malformed.
     */
    private static function originOf(UriInterface $requestUri): string
    {
        $authority = $requestUri->getAuthority();
        if ($authority === '') {
            return '';
        }

        $scheme = $requestUri->getScheme();
        if ($scheme === '') {
            return '';
        }

        return $scheme . '://' . $authority;
    }
}
