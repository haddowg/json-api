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
 * A configured base's path is the server's mount: `/api/v5`, or the path of
 * `https://host/api/v5`. Resource links append a server-relative path to the base,
 * but a document link reuses the request path, which already carries that mount
 * when the server is routed under a prefix. {@see self::document()} removes it
 * first, so the mount appears once.
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
     * The URL of the document a request addressed: the resolved base plus the request
     * path, without the query.
     *
     * When a base is configured and the request path starts with the base's own path
     * on a segment boundary, that leading mount is removed before the base is
     * prepended, so a server mounted under `/api/v5` with a base of `/api/v5` links to
     * `/api/v5/albums`, not `/api/v5/api/v5/albums`. A request path that doesn't carry
     * the mount, such as one a proxy has already stripped, is used as it is.
     */
    public static function document(string $configuredBaseUri, UriInterface $requestUri): string
    {
        $base = self::resolve($configuredBaseUri, $requestUri);
        $path = $requestUri->getPath();

        if ($configuredBaseUri !== '') {
            $mount = \rtrim((string) \parse_url($base, \PHP_URL_PATH), '/');
            if ($mount !== '' && ($path === $mount || \str_starts_with($path, $mount . '/'))) {
                $path = \substr($path, \strlen($mount));
            }
        }

        return $base . $path;
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
