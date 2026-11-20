<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Resource\Constraint\In;

/**
 * The `In` a filter's `enum()` appends, shared by {@see BuildsEnumValue} and
 * {@see HasValueConstraints} so the builders and a self-contained filter declare an enum
 * the same way.
 *
 * @internal
 */
final class EnumValueConstraint
{
    /**
     * An `In` over the backing values of `$enum`'s cases that keeps the enum's class.
     *
     * @param class-string<\BackedEnum> $enum
     *
     * @return In<int|string>
     *
     * @throws \InvalidArgumentException when `$enum` is not a backed enum, since a pure enum has no wire value
     */
    public static function of(string $key, string $enum): In
    {
        if (!\is_subclass_of($enum, \BackedEnum::class)) {
            throw new \InvalidArgumentException(\sprintf(
                'Filter "%s" cannot take enum(%s): enum() takes a backed enum, whose cases have the string or int values a request carries.',
                $key,
                $enum,
            ));
        }

        $values = \array_map(static fn(\BackedEnum $case): int|string => $case->value, $enum::cases());

        return new In(\array_values($values), enumClass: $enum);
    }
}
