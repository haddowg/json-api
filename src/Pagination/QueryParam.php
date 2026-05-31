<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

/**
 * Internal helpers for reading values out of the raw `page[…]` parameter map.
 *
 * @internal
 */
final class QueryParam
{
    /**
     * Integer extraction with silent fallback: an absent or non-numeric value
     * yields the default (mirrors the request-side pagination parsing rule, which
     * never throws on a missing/garbage `page[…]` value).
     *
     * @param array<string, mixed> $params
     */
    public static function int(array $params, string $key, int $default): int
    {
        return isset($params[$key]) && \is_numeric($params[$key]) ? (int) $params[$key] : $default;
    }
}
