<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Serve;

/**
 * The entity tags an adapter puts on a served document, and the `If-None-Match` check.
 * Strings in and out, so each adapter maps its own request and response.
 *
 * There are three tags. With no serve-time decorator the tag is the {@see hash()} of the
 * stored artifact. When every decorator can describe its output with a cache key, it is
 * the {@see keyed()} hash, which an adapter can compare before loading or decorating
 * anything. Otherwise it is the {@see hash()} of the final body, because a partial key
 * would let two different bodies share a tag.
 */
final class EntityTag
{
    private const int JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    /**
     * The hash of an artifact or a response body.
     */
    public static function hash(string $bytes): string
    {
        return \hash('xxh128', $bytes);
    }

    /**
     * The hash of a decorated response from the artifact's hash and each decorator's cache
     * key, in the order the decorators run. Each entry pairs the decorator's class name, so
     * adding, removing or reordering decorators changes the tag, with its key.
     *
     * @param list<array{0: string, 1: array<array-key, mixed>|string}> $keys
     *
     * @throws \JsonException when a key isn't JSON-encodable
     */
    public static function keyed(string $artifactHash, array $keys): string
    {
        return self::hash($artifactHash . "\n" . \json_encode($keys, self::JSON_FLAGS));
    }

    /**
     * Whether an `If-None-Match` header value matches `$etag`. The comparison is weak
     * (RFC 9110 section 13.1.2), so a CDN that compressed the body and handed the client a
     * `W/` copy of the tag still matches, and `*` matches anything. Either side may be
     * quoted or bare.
     */
    public static function matches(string $etag, ?string $ifNoneMatch): bool
    {
        if ($ifNoneMatch === null || \preg_match_all('#(?:W/)?"[^"]*"|[^\s,]+#', $ifNoneMatch, $candidates) === false) {
            return false;
        }

        $etag = self::opaque($etag);
        foreach ($candidates[0] as $candidate) {
            if ($candidate === '*' || self::opaque($candidate) === $etag) {
                return true;
            }
        }

        return false;
    }

    private static function opaque(string $etag): string
    {
        $etag = \trim($etag);
        if (\str_starts_with($etag, 'W/')) {
            $etag = \substr($etag, 2);
        }
        if (\strlen($etag) >= 2 && $etag[0] === '"' && $etag[-1] === '"') {
            $etag = \substr($etag, 1, -1);
        }

        return $etag;
    }
}
