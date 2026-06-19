<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Intent-named **boolean** match — keeps rows whose column equals the given
 * boolean, coercing the wire value (`1`/`true`/`on`/`yes` → `true`,
 * `0`/`false`/`off`/`no`/`''` → `false`, via `FILTER_VALIDATE_BOOLEAN`) so a
 * truthy string compares as a real boolean.
 *
 * A thin {@see Where} subclass presetting the `=` operator, the boolean coercion
 * deserializer ({@see Where::asBoolean()}) and the matching `boolean()` value
 * constraint, so coercion, validation and the OpenAPI value schema all come from
 * one declaration; a handler's existing `instanceof Where` arm dispatches it
 * unchanged.
 *
 * The `=` operator is this convenience's identity and cannot be overridden — the
 * `$operator` argument exists only for {@see Where::make()} signature parity, and a
 * non-`=` value is a loud {@see \InvalidArgumentException} ({@see FixedOperator}).
 */
final readonly class Boolean extends \haddowg\JsonApi\Resource\Filter\Where
{
    public static function make(string $key, ?string $column = null, string $operator = '='): static
    {
        FixedOperator::guard(self::class, '=', $operator);

        return parent::make($key, $column, '=')
            ->asBoolean()
            ->boolean()
            ->describedAs('Matches the given boolean (true or false).');
    }
}
