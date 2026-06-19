<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Intent-named substring match — keeps rows whose column **contains** the given
 * value (the `like` operator: in-memory `stripos`, a database adapter `LIKE
 * '%…%'` with the wildcards added by the handler, never leaked to the client).
 *
 * A thin {@see Where} subclass: it only presets the operator and a string-value
 * OpenAPI description, so a handler's existing `instanceof Where` arm dispatches
 * it unchanged. The value schema stays a plain string.
 *
 * The `like` operator is this convenience's identity and cannot be overridden —
 * the `$operator` argument exists only for {@see Where::make()} signature parity,
 * and a non-`like` value is a loud {@see \InvalidArgumentException}
 * ({@see FixedOperator}).
 */
final readonly class Contains extends \haddowg\JsonApi\Resource\Filter\Where
{
    public static function make(string $key, ?string $column = null, string $operator = 'like'): static
    {
        FixedOperator::guard(self::class, 'like', $operator);

        return parent::make($key, $column, 'like')
            ->describedAs('Matches values containing the given substring.');
    }
}
