<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Exception\CursorMalformed;

/**
 * Storage-agnostic base64url codec for cursor tokens.
 *
 * A token is `base64url(json(<column => boundary value>, …, _pointsToNextItems
 * => bool, _d => <column => descending>))` — the boundary row's value for every
 * keyset column (incl. the PK key), a reserved forward/backward flag, and a
 * reserved per-column direction map — URL-safe via
 * `rtrim(strtr(base64, '+/', '-_'), '=')`. **Opaque, not signed or encrypted**
 * (mirroring Laravel's cursor): tampering is caught only by the downstream
 * keyset/stale checks, not cryptographically.
 *
 * The codec is **scalar-only**: the caller (the executing provider) passes
 * already-JSON-safe scalars — dates stringified, ids as scalars — and the codec
 * neither inspects domain types nor resolves the active sort. {@see decode()}
 * validates the wire shape and throws {@see CursorMalformed} on anything that is
 * not a base64url-encoded JSON object of scalars carrying the direction flag.
 *
 * @internal
 */
final class CursorCodec
{
    /**
     * The reserved key carrying the forward/backward flag inside the encoded
     * tuple, distinguished from a real sort column by its leading underscore (a
     * JSON:API member name cannot begin with one, so it can never collide with a
     * column).
     */
    private const string DIRECTION_KEY = '_pointsToNextItems';

    /**
     * The reserved key carrying the per-column sort-direction map (`column =>
     * descending`). Like {@see DIRECTION_KEY} its leading underscore keeps it
     * clear of any JSON:API member name or sort column, so the directions cannot
     * be mistaken for a boundary value.
     */
    private const string DIRECTIONS_KEY = '_d';

    /**
     * Encodes a boundary into an opaque base64url token. The caller supplies the
     * column => value map (every value a JSON-safe scalar or null), the
     * forward/backward flag, and the per-column sort-direction map.
     */
    public function encode(CursorBoundary $boundary): string
    {
        $tuple = $boundary->values;
        $tuple[self::DIRECTION_KEY] = $boundary->pointsToNextItems;
        $tuple[self::DIRECTIONS_KEY] = $boundary->descending;

        $json = \json_encode($tuple, \JSON_THROW_ON_ERROR);

        return \rtrim(\strtr(\base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Decodes a token back into a {@see CursorBoundary}.
     *
     * @param string $token     the base64url cursor token
     * @param string $parameter the cursor parameter the token came from (e.g. `page[after]`), used for the error source
     *
     * @throws CursorMalformed when the token is not base64url, not JSON, not an object, missing either reserved key, or carries a non-scalar value or malformed direction map
     */
    public function decode(string $token, string $parameter): CursorBoundary
    {
        $binary = \base64_decode(\strtr($token, '-_', '+/'), true);
        if ($binary === false) {
            throw new CursorMalformed($parameter);
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($binary, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new CursorMalformed($parameter);
        }

        if (
            !\is_array($decoded)
            || !\array_key_exists(self::DIRECTION_KEY, $decoded)
            || !\array_key_exists(self::DIRECTIONS_KEY, $decoded)
        ) {
            throw new CursorMalformed($parameter);
        }

        $pointsToNextItems = $decoded[self::DIRECTION_KEY];
        if (!\is_bool($pointsToNextItems)) {
            throw new CursorMalformed($parameter);
        }
        unset($decoded[self::DIRECTION_KEY]);

        $descending = $this->decodeDirections($decoded[self::DIRECTIONS_KEY], $parameter);
        unset($decoded[self::DIRECTIONS_KEY]);

        $values = [];
        foreach ($decoded as $column => $value) {
            // A JSON object decodes to a string-keyed array; a JSON array (list)
            // would yield int keys, which is not a column => value boundary.
            if (!\is_string($column) || (!\is_scalar($value) && $value !== null)) {
                throw new CursorMalformed($parameter);
            }
            $values[$column] = $value;
        }

        return new CursorBoundary($values, $pointsToNextItems, $descending);
    }

    /**
     * Validates the reserved direction map into a `column => descending` array.
     * An empty map decodes from a JSON `[]` to an empty PHP array (the no-sort
     * boundary); a populated map must be a string-keyed object of booleans.
     *
     * @return array<string, bool>
     *
     * @throws CursorMalformed when the value is not a map of column => bool
     */
    private function decodeDirections(mixed $raw, string $parameter): array
    {
        if (!\is_array($raw)) {
            throw new CursorMalformed($parameter);
        }

        $descending = [];
        foreach ($raw as $column => $value) {
            if (!\is_string($column) || !\is_bool($value)) {
                throw new CursorMalformed($parameter);
            }
            $descending[$column] = $value;
        }

        return $descending;
    }
}
