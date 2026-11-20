<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

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
        return $this->constrain(EnumValueConstraint::of($this->key, $enum));
    }
}
