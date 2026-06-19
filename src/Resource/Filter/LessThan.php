<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Intent-named numeric **less-than** — keeps rows whose column is strictly less
 * than the given number, comparing numerically (the incoming string is coerced
 * to `int`/`float`).
 *
 * A thin {@see Where} subclass presetting the `<` operator, a numeric coercion
 * deserializer and the `numeric()` value constraint; a handler's existing
 * `instanceof Where` arm dispatches it unchanged.
 *
 * The `<` operator is this convenience's identity and cannot be overridden — the
 * `$operator` argument exists only for {@see Where::make()} signature parity, and a
 * non-`<` value is a loud {@see \InvalidArgumentException} ({@see FixedOperator}).
 */
final readonly class LessThan extends \haddowg\JsonApi\Resource\Filter\Where
{
    public static function make(string $key, ?string $column = null, string $operator = '<'): static
    {
        FixedOperator::guard(self::class, '<', $operator);

        return parent::make($key, $column, '<')
            ->deserializeUsing(\haddowg\JsonApi\Resource\Filter\NumericCoercion::deserializer())
            ->numeric()
            ->describedAs('Matches values less than the given number.');
    }
}
