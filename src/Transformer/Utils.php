<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Transformer;

/**
 * Internal URL-building helper used by the {@see \haddowg\JsonApi\Pagination\Page}
 * value objects to build pagination links.
 *
 * Only `getUri` lives here; other query-param helpers are inlined at their use
 * sites.
 *
 * @internal
 */
final class Utils
{
    /**
     * Builds a URI by merging the base URI's own query string, the supplied
     * `$queryString`, and any `$additionalQueryParams`.  `$additionalQueryParams`
     * takes precedence over both; `$queryString` overrides the URI's own params.
     *
     * @param array<string, mixed> $additionalQueryParams
     */
    public static function getUri(string $uri, string $queryString, array $additionalQueryParams): string
    {
        $uriQueryStringSeparator = \strpos($uri, '?');
        if ($uriQueryStringSeparator === false) {
            $uriWithoutQueryString = $uri;
            $uriQueryString = '';
        } else {
            $uriWithoutQueryString = \substr($uri, 0, $uriQueryStringSeparator);
            $uriQueryString = \substr($uri, $uriQueryStringSeparator + 1);
        }

        $parsedUriQueryString = [];
        \parse_str(\urldecode($uriQueryString), $parsedUriQueryString);

        $parsedQueryString = [];
        \parse_str(\urldecode($queryString), $parsedQueryString);

        $parsedFinalQueryString = \array_merge($parsedUriQueryString, $parsedQueryString, $additionalQueryParams);

        $finalQueryString = \http_build_query($parsedFinalQueryString);

        return $uriWithoutQueryString . ($finalQueryString === '' ? '' : '?' . $finalQueryString);
    }
}
