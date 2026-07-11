<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Intent-named prefix match — keeps rows whose column **starts with** the given
 * value (the `starts` operator: in-memory a case-insensitive `str_starts_with`,
 * a database adapter `LIKE '…%'` with the trailing wildcard added by the
 * handler).
 *
 * A {@see WhereBuilder} facade presetting the operator and a string-value
 * description and building a plain {@see Where}; a handler's existing
 * `instanceof Where` arm dispatches it unchanged.
 *
 * The `starts` operator is this convenience's identity and cannot be overridden —
 * the `$operator` argument exists only for {@see WhereBuilder::make()} signature
 * parity, and a non-`starts` value is a loud {@see \InvalidArgumentException}
 * ({@see FixedOperator}).
 */
final class StartsWith extends WhereBuilder
{
    public static function make(string $key, ?string $column = null, string $operator = 'starts'): static
    {
        FixedOperator::guard(self::class, 'starts', $operator);

        return parent::make($key, $column, 'starts')
            ->describedAs('Matches values starting with the given prefix.');
    }
}
