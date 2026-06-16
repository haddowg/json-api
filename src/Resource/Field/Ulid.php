<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A self-contained ULID generator. Core is framework-agnostic, so it does not
 * depend on `symfony/uid`: a ULID is a 48-bit big-endian millisecond timestamp
 * followed by 80 bits of randomness, the whole 128 bits encoded as 26 Crockford
 * base32 characters.
 *
 * Used to source a {@see Id} field declared `ulid()->generated()`.
 *
 * @internal
 */
final class Ulid
{
    /**
     * Crockford base32 alphabet (uppercase, no I/L/O/U).
     */
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * Generates a fresh ULID as a 26-character uppercase Crockford base32 string —
     * a 48-bit timestamp prefix followed by 80 bits of randomness, the whole 128
     * bits front-padded to 130 bits and encoded as 26 groups of 5 bits.
     */
    public static function generate(): string
    {
        $timestamp = (int) (\microtime(true) * 1000);

        // 6 timestamp bytes (48 bits, big-endian) + 10 random bytes (80 bits).
        $bytes = '';
        for ($shift = 40; $shift >= 0; $shift -= 8) {
            $bytes .= \chr(($timestamp >> $shift) & 0xFF);
        }
        $bytes .= \random_bytes(10);

        return self::encode($bytes);
    }

    /**
     * Encodes 16 bytes (128 bits) as 26 Crockford base32 characters in canonical
     * ULID order: the 128 bits are front-padded with two zero bits to 130 bits,
     * then emitted as 26 groups of 5 bits, most-significant first — so the result
     * is lexicographically sortable and its leading character is `0-7`.
     */
    private static function encode(string $bytes): string
    {
        // Front-pad with two zero bits so the bit stream is a multiple of 5.
        $accumulator = 0;
        $bitsBuffered = 2;

        $output = '';
        for ($i = 0; $i < 16; $i++) {
            $accumulator = ($accumulator << 8) | \ord($bytes[$i]);
            $bitsBuffered += 8;
            while ($bitsBuffered >= 5) {
                $bitsBuffered -= 5;
                $output .= self::ALPHABET[($accumulator >> $bitsBuffered) & 0x1F];
            }
        }

        return $output;
    }
}
