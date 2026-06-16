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
            if (!self::isJsonApiMediaType($mediaType)) {
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
     * Whether an `Accept` header is acceptable JSON:API parameter-wise. Unlike
     * {@see isValid()} — the Content-Type rule, where ANY non-`ext`/`profile`
     * parameter is unacceptable — the spec requires a `406` only when EVERY
     * `application/vnd.api+json` instance in `Accept` carries a forbidden media-type
     * parameter: a single conforming instance makes the header acceptable. A header
     * asserting no JSON:API instance at all (absent, `*\/*`, …) is acceptable. The
     * optional `q` weight and any accept-extension parameters following it are not
     * media-type parameters and are ignored.
     */
    public static function accepts(string $header): bool
    {
        $sawJsonApi = false;
        foreach (self::split($header) as $mediaType) {
            if (!self::isJsonApiMediaType($mediaType)) {
                continue;
            }

            $sawJsonApi = true;
            if (self::instanceConforms($mediaType)) {
                return true;
            }
        }

        return $sawJsonApi === false;
    }

    /**
     * Whether one `application/vnd.api+json` Accept instance carries only `ext`
     * and/or `profile` media-type parameters. Media-type parameters precede the `q`
     * weight; the weight and any trailing accept-extension parameters are ignored.
     */
    private static function instanceConforms(string $mediaType): bool
    {
        $beforeWeight = \preg_split('/;\s*q\s*=/i', $mediaType, 2)[0] ?? $mediaType;

        if (\preg_match_all('/;\s*([A-Za-z0-9.+-]+)\s*=/', $beforeWeight, $matches) === 0) {
            return true;
        }

        foreach ($matches[1] as $name) {
            $name = \strtolower($name);
            if ($name !== 'ext' && $name !== 'profile') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a media-type instance *is* the JSON:API media type — matched at the
     * type/subtype boundary (the token before any parameter), not as a substring,
     * so a different `+json` subtype (e.g. `application/vnd.api+json-patch+json`) is
     * not mistaken for JSON:API and have its parameters policed.
     */
    private static function isJsonApiMediaType(string $mediaType): bool
    {
        return \strtolower(\trim(\explode(';', $mediaType, 2)[0])) === 'application/vnd.api+json';
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
