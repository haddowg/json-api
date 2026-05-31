<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Request;

/**
 * Single source of truth for the JSON:API media-type parameter rule, shared by
 * request Content-Type/Accept validation, response Content-Type validation, and
 * the request-side `profile`/`ext` parameter parsing.
 *
 * Per JSON:API 1.1 the media type `application/vnd.api+json` MUST NOT carry any
 * media-type parameter other than `ext` or `profile`. {@see isValid()} enforces
 * that well-formedness rule. Whether an `ext` is actually *supported* is a
 * separate negotiation concern (it requires the server's supported-extension
 * set) handled in {@see \haddowg\JsonApi\Negotiation\RequestValidator}.
 *
 * @internal
 */
final class MediaType
{
    /**
     * Whether a media-type header is valid JSON:API parameter-wise: every
     * `application/vnd.api+json` instance carries only `ext` and/or `profile`
     * parameters. A header that does not assert the JSON:API media type, or
     * asserts it with no parameters, is treated as valid.
     */
    public static function isValid(string $header): bool
    {
        foreach (self::split($header) as $mediaType) {
            if (\stripos($mediaType, 'application/vnd.api+json') === false) {
                continue;
            }

            if (\preg_match_all('/;\s*([A-Za-z0-9.+-]+)\s*=/', $mediaType, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $name) {
                $name = \strtolower($name);
                if ($name !== 'ext' && $name !== 'profile') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Splits a header into its individual media-type instances on commas that are
     * not inside a quoted parameter value (a quoted `ext`/`profile` value may
     * itself contain a comma).
     *
     * @return list<string>
     */
    public static function split(string $header): array
    {
        $instances = [];
        $current = '';
        $inQuotes = false;

        $length = \strlen($header);
        for ($i = 0; $i < $length; $i++) {
            $char = $header[$i];

            if ($char === '"') {
                $inQuotes = $inQuotes === false;
                $current .= $char;

                continue;
            }

            if ($char === ',' && $inQuotes === false) {
                $instances[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $instances[] = $current;

        return $instances;
    }
}
