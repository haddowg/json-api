<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Intent-named numeric **less-than** — keeps rows whose column is strictly less
 * than the given number, comparing numerically (the incoming string is coerced
 * to `int`/`float`).
 *
 * A {@see WhereBuilder} facade presetting the `<` operator, a numeric coercion
 * deserializer and the `numeric()` value constraint and building a plain
 * {@see Where}; a handler's existing `instanceof Where` arm dispatches it unchanged.
 *
 * The `<` operator is this convenience's identity and cannot be overridden — the
 * `$operator` argument exists only for {@see WhereBuilder::make()} signature parity,
 * and a non-`<` value is a loud {@see \InvalidArgumentException} ({@see FixedOperator}).
 */
final class LessThan extends WhereBuilder
{
    public static function make(string $key, ?string $column = null, string $operator = '<'): static
    {
        FixedOperator::guard(self::class, '<', $operator);

        return parent::make($key, $column, '<')
            ->deserializeUsing(NumericCoercion::deserializer())
            ->numeric()
            ->describedAs('Matches values less than the given number.');
    }
}
