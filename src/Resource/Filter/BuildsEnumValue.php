<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Resource\Constraint\In;

/**
 * The `enum()` shortcut of a value-carrying filter builder, mirroring a field
 * builder's: it restricts the value to the backing values of a backed enum's cases.
 * A list filter applies it to each member. The filter still compares the backing
 * value, never the case, so the shortcut only validates and documents. The OpenAPI
 * document references the enum's hoisted component, the one a field declaring the
 * same enum references.
 *
 * Requires the host to use {@see BuildsValueConstraints}.
 */
trait BuildsEnumValue
{
    /**
     * @param class-string<\BackedEnum> $enum
     *
     * @return static
     *
     * @throws \InvalidArgumentException when `$enum` is not a backed enum, since a pure enum has no wire value
     */
    public function enum(string $enum): static
    {
        if (!\is_subclass_of($enum, \BackedEnum::class)) {
            throw new \InvalidArgumentException(\sprintf(
                'Filter "%s" cannot take enum(%s): enum() takes a backed enum, whose cases have the string or int values a request carries.',
                $this->key,
                $enum,
            ));
        }

        $values = \array_map(static fn(\BackedEnum $case): int|string => $case->value, $enum::cases());

        return $this->constrain(new In(\array_values($values), enumClass: $enum));
    }
}
