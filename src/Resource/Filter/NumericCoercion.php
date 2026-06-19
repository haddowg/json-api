<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Shared value coercion for the numeric convenience filters
 * ({@see Numeric}, {@see GreaterThan}, {@see GreaterThanOrEqual},
 * {@see LessThan}, {@see LessThanOrEqual}): turns a numeric **string** into an
 * `int` (no decimal point) or `float` (with one) so comparisons run numerically
 * rather than lexically — `'18' > '5'` is `true` numerically but `false` as a
 * string compare. A value that is not a numeric string is returned unchanged, so
 * a constraint-rejected value still reaches the validator as-sent.
 *
 * @internal
 */
final class NumericCoercion
{
    /**
     * The deserializer the numeric conveniences preset on their {@see Where}.
     *
     * @return \Closure(mixed): mixed
     */
    public static function deserializer(): \Closure
    {
        return static fn(mixed $value): mixed => self::coerce($value);
    }

    public static function coerce(mixed $value): mixed
    {
        if (\is_int($value) || \is_float($value)) {
            return $value;
        }

        if (\is_string($value) && \is_numeric($value)) {
            return \str_contains($value, '.') || \str_contains($value, 'e') || \str_contains($value, 'E')
                ? (float) $value
                : (int) $value;
        }

        return $value;
    }
}
