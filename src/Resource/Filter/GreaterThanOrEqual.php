<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Intent-named numeric **greater-than-or-equal** — keeps rows whose column is at
 * least the given number, comparing numerically (the incoming string is coerced
 * to `int`/`float`, so `filter[age]=18` keeps `18` and above).
 *
 * A {@see WhereBuilder} facade presetting the `>=` operator, a numeric coercion
 * deserializer and the `numeric()` value constraint and building a plain
 * {@see Where}; a handler's existing `instanceof Where` arm dispatches it unchanged.
 *
 * The `>=` operator is this convenience's identity and cannot be overridden — the
 * `$operator` argument exists only for {@see WhereBuilder::make()} signature parity,
 * and a non-`>=` value is a loud {@see \InvalidArgumentException} ({@see FixedOperator}).
 */
final class GreaterThanOrEqual extends WhereBuilder
{
    public static function make(string $key, ?string $column = null, string $operator = '>='): static
    {
        FixedOperator::guard(self::class, '>=', $operator);

        return parent::make($key, $column, '>=')
            ->deserializeUsing(NumericCoercion::deserializer())
            ->numeric()
            ->describedAs('Matches values greater than or equal to the given number.');
    }
}
