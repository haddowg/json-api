<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Intent-named numeric **equality** — keeps rows whose column equals the given
 * number, comparing numerically (the incoming string is coerced to `int`/`float`
 * before comparison, so `filter[rating]=4` matches an integer `4`).
 *
 * A thin {@see Where} subclass presetting the `=` operator, a numeric coercion
 * deserializer and the matching `numeric()` value constraint, so coercion,
 * validation and the OpenAPI value schema all come from one declaration; a
 * handler's existing `instanceof Where` arm dispatches it unchanged.
 *
 * The `=` operator is this convenience's identity and cannot be overridden — the
 * `$operator` argument exists only for {@see Where::make()} signature parity, and a
 * non-`=` value is a loud {@see \InvalidArgumentException} ({@see FixedOperator}).
 */
final readonly class Numeric extends \haddowg\JsonApi\Resource\Filter\Where
{
    public static function make(string $key, ?string $column = null, string $operator = '='): static
    {
        FixedOperator::guard(self::class, '=', $operator);

        return parent::make($key, $column, '=')
            ->deserializeUsing(\haddowg\JsonApi\Resource\Filter\NumericCoercion::deserializer())
            ->numeric()
            ->describedAs('Matches the given number.');
    }
}
