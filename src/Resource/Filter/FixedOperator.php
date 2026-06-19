<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Shared guard for the intent-named scalar conveniences
 * ({@see Contains}, {@see StartsWith}, {@see EndsWith}, {@see Numeric},
 * {@see GreaterThan}, {@see GreaterThanOrEqual}, {@see LessThan},
 * {@see LessThanOrEqual}, {@see Boolean}).
 *
 * Each convenience's comparison operator **is its identity** — a `Contains` is a
 * `like`, a `GreaterThan` is a `>` — so the operator must not be overridable. The
 * conveniences nonetheless carry the `$operator` argument on their `make()` purely
 * to stay signature-compatible with the parent {@see Where::make(string, ?string,
 * string)} (PHP forbids a child override that drops a trailing optional parameter).
 * Without this guard a caller could pass `GreaterThan::make('age', null, '<')` and
 * the convenience would *silently* ignore it and still apply `>` — a wrong-result
 * footgun. This turns that into a loud, immediate authoring error.
 *
 * @internal
 */
final class FixedOperator
{
    /**
     * Rejects any operator other than the convenience's fixed identity operator.
     *
     * @param class-string $convenience the convenience class, for the message
     * @param string       $fixed       the convenience's identity operator
     * @param string       $given       the operator the caller supplied
     *
     * @throws \InvalidArgumentException when `$given` is not the fixed operator
     */
    public static function guard(string $convenience, string $fixed, string $given): void
    {
        if ($given !== $fixed) {
            throw new \InvalidArgumentException(\sprintf(
                '%s has a fixed `%s` operator that cannot be overridden; received `%s`. '
                . 'Use a plain %s::make(..., operator: ...) for a custom operator.',
                $convenience,
                $fixed,
                $given,
                Where::class,
            ));
        }
    }
}
