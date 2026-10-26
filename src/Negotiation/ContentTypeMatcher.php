<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Negotiation;

use haddowg\JsonApi\Request\MediaType;

/**
 * Matches a request `Content-Type` against the media types an endpoint accepts, such as
 * a custom action that takes a raw body. Matching compares `type/subtype` only and is
 * case-insensitive: request parameters (`charset`, a multipart `boundary`) never stop a
 * match, and an accepted entry may be a wildcard (`image/*`, `*\/*`).
 *
 * It also flags the three media types a browser sends cross-origin without a CORS
 * preflight. {@see RequestValidator::negotiateContentType()} refuses those unless the
 * endpoint lists them by name, so a wildcard never opts an endpoint into them.
 */
final class ContentTypeMatcher
{
    /**
     * The media types a cross-origin form or `fetch()` can send without a preflight.
     */
    public const array CORS_SAFELISTED = [
        'application/x-www-form-urlencoded',
        'multipart/form-data',
        'text/plain',
    ];

    /**
     * The most specific accepted entry the `Content-Type` matches: an exact entry wins over
     * `type/*`, which wins over `*\/*`. Returns `null` when the header is absent, names
     * more than one media type, or matches nothing.
     *
     * @param list<string> $accepted the accepted media types; parameters on an entry are ignored
     */
    public static function match(string $contentType, array $accepted): ?ContentTypeMatch
    {
        $instances = MediaType::split($contentType);
        if (\count($instances) !== 1) {
            return null;
        }

        $mediaType = MediaType::essence($instances[0]);
        if (\preg_match('#^[^/\s]+/[^/\s]+$#', $mediaType) !== 1) {
            return null;
        }
        [$type] = \explode('/', $mediaType, 2);

        $best = null;
        $bestRank = 0;
        foreach ($accepted as $entry) {
            $entry = MediaType::essence($entry);
            $rank = match (true) {
                $entry === $mediaType => 3,
                $entry === $type . '/*' => 2,
                $entry === '*/*' => 1,
                default => 0,
            };
            if ($rank > $bestRank) {
                $best = $entry;
                $bestRank = $rank;
            }
        }

        return $best === null
            ? null
            : new ContentTypeMatch($mediaType, $best, self::isCorsSafelisted($mediaType));
    }

    /**
     * Whether a media type is one a browser sends cross-origin without a preflight.
     * Parameters and case are ignored.
     */
    public static function isCorsSafelisted(string $mediaType): bool
    {
        return \in_array(MediaType::essence($mediaType), self::CORS_SAFELISTED, true);
    }
}
