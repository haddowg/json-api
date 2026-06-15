<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Exception\MalformedCursor;

/**
 * Storage-agnostic base64url codec for cursor tokens.
 *
 * A token is `base64url(json(<column => boundary value>, …, _pointsToNextItems
 * => bool))` — the boundary row's value for every keyset column (incl. the PK
 * key) plus a reserved direction flag — URL-safe via
 * `rtrim(strtr(base64, '+/', '-_'), '=')`. **Opaque, not signed or encrypted**
 * (mirroring Laravel's cursor): tampering is caught only by the downstream
 * keyset/stale checks, not cryptographically.
 *
 * The codec is **scalar-only**: the caller (the executing provider) passes
 * already-JSON-safe scalars — dates stringified, ids as scalars — and the codec
 * neither inspects domain types nor resolves the active sort. {@see decode()}
 * validates the wire shape and throws {@see MalformedCursor} on anything that is
 * not a base64url-encoded JSON object of scalars carrying the direction flag.
 *
 * @internal
 */
final class CursorCodec
{
    /**
     * The reserved key carrying the direction flag inside the encoded tuple,
     * distinguished from a real sort column by its leading underscore (a JSON:API
     * member name cannot begin with one, so it can never collide with a column).
     */
    private const string DIRECTION_KEY = '_pointsToNextItems';

    /**
     * Encodes a boundary into an opaque base64url token. The caller supplies the
     * column => value map (every value a JSON-safe scalar or null) and the
     * forward/backward direction flag.
     */
    public function encode(CursorBoundary $boundary): string
    {
        $tuple = $boundary->values;
        $tuple[self::DIRECTION_KEY] = $boundary->pointsToNextItems;

        $json = \json_encode($tuple, \JSON_THROW_ON_ERROR);

        return \rtrim(\strtr(\base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Decodes a token back into a {@see CursorBoundary}.
     *
     * @param string $token     the base64url cursor token
     * @param string $parameter the cursor parameter the token came from (e.g. `page[after]`), used for the error source
     *
     * @throws MalformedCursor when the token is not base64url, not JSON, not an object, missing the direction flag, or carries a non-scalar value
     */
    public function decode(string $token, string $parameter): CursorBoundary
    {
        $binary = \base64_decode(\strtr($token, '-_', '+/'), true);
        if ($binary === false) {
            throw new MalformedCursor($parameter);
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($binary, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new MalformedCursor($parameter);
        }

        if (!\is_array($decoded) || !\array_key_exists(self::DIRECTION_KEY, $decoded)) {
            throw new MalformedCursor($parameter);
        }

        $pointsToNextItems = $decoded[self::DIRECTION_KEY];
        if (!\is_bool($pointsToNextItems)) {
            throw new MalformedCursor($parameter);
        }
        unset($decoded[self::DIRECTION_KEY]);

        $values = [];
        foreach ($decoded as $column => $value) {
            // A JSON object decodes to a string-keyed array; a JSON array (list)
            // would yield int keys, which is not a column => value boundary.
            if (!\is_string($column) || (!\is_scalar($value) && $value !== null)) {
                throw new MalformedCursor($parameter);
            }
            $values[$column] = $value;
        }

        return new CursorBoundary($values, $pointsToNextItems);
    }
}
