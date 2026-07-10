<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A **field builder** facade for an IP-address string. Accepts both IPv4 and IPv6
 * by default; narrow with {@see v4()} / {@see v6()}. Presets an `IpFormat`
 * constraint and builds a plain {@see Str}. Equivalent to `Str::make($name)->ip()`.
 */
final class Ip extends StrBuilder
{
    /**
     * @return static
     */
    public static function make(string $name): static
    {
        $field = new static($name);

        return $field->ip();
    }

    /**
     * @return static
     */
    public function v4(): static
    {
        return $this->ip(4);
    }

    /**
     * @return static
     */
    public function v6(): static
    {
        return $this->ip(6);
    }

    /**
     * @return static
     */
    public function both(): static
    {
        return $this->ip();
    }
}
