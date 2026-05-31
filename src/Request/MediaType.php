<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Request;

/**
 * Single source of truth for the JSON:API media-type parameter rule, shared by
 * request Content-Type/Accept validation and response Content-Type validation.
 *
 * Per JSON:API the media type `application/vnd.api+json` is only modified by
 * significant parameters; this package follows yin in treating **only `profile`**
 * as acceptable (the `ext` parameter is not yet handled — see the content-
 * negotiation row in `docs/spec-compliance.md`).
 *
 * @internal
 */
final class MediaType
{
    /**
     * Whether a media-type header is valid JSON:API parameter-wise: it carries no
     * media-type parameters at all, or its parameter is `profile`. An empty header
     * is treated as valid (no media type asserted).
     */
    public static function isValid(string $header): bool
    {
        $matches = [];
        $isMatching = \preg_match('/^.*application\/vnd\.api\+json\s*;\s*([A-Za-z0-9]+)\s*=.*$/i', $header, $matches);

        return $isMatching === 0 || (isset($matches[1]) && \strtolower($matches[1]) === 'profile');
    }
}
