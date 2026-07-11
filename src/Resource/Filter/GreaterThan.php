<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Intent-named numeric **greater-than** — keeps rows whose column is strictly
 * greater than the given number, comparing numerically (the incoming string is
 * coerced to `int`/`float`, so `filter[age]=6` keeps `18` but not `5` — a string
 * compare would wrongly keep `5`).
 *
 * A {@see WhereBuilder} facade presetting the `>` operator, a numeric coercion
 * deserializer and the `numeric()` value constraint and building a plain
 * {@see Where}; a handler's existing `instanceof Where` arm dispatches it unchanged.
 *
 * The `>` operator is this convenience's identity, so it cannot be overridden: the
 * `$operator` argument exists only to keep {@see make()} signature-compatible with
 * {@see WhereBuilder::make()}, and passing anything other than `>` is a loud
 * {@see \InvalidArgumentException} rather than a silently-ignored value.
 */
final class GreaterThan extends WhereBuilder
{
    public static function make(string $key, ?string $column = null, string $operator = '>'): static
    {
        FixedOperator::guard(self::class, '>', $operator);

        return parent::make($key, $column, '>')
            ->deserializeUsing(NumericCoercion::deserializer())
            ->numeric()
            ->describedAs('Matches values greater than the given number.');
    }
}
