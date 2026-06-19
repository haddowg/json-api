<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Intent-named suffix match — keeps rows whose column **ends with** the given
 * value (the `ends` operator: in-memory a case-insensitive `str_ends_with`, a
 * database adapter `LIKE '%…'` with the leading wildcard added by the handler).
 *
 * A thin {@see Where} subclass presetting the operator and a string-value
 * description; a handler's existing `instanceof Where` arm dispatches it
 * unchanged.
 *
 * The `ends` operator is this convenience's identity and cannot be overridden —
 * the `$operator` argument exists only for {@see Where::make()} signature parity,
 * and a non-`ends` value is a loud {@see \InvalidArgumentException}
 * ({@see FixedOperator}).
 */
final readonly class EndsWith extends \haddowg\JsonApi\Resource\Filter\Where
{
    public static function make(string $key, ?string $column = null, string $operator = 'ends'): static
    {
        FixedOperator::guard(self::class, 'ends', $operator);

        return parent::make($key, $column, 'ends')
            ->describedAs('Matches values ending with the given suffix.');
    }
}
