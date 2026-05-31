<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A string attribute that validates UUID format by default. Equivalent to
 * `Str::make($name)->uuid()`.
 */
final class Uuid extends Str
{
    /**
     * @return static
     */
    public static function make(string $name): static
    {
        $field = new static($name);

        return $field->uuid();
    }

    /**
     * Narrows to a specific RFC 4122 UUID version.
     *
     * @return static
     */
    public function version(int $version): static
    {
        return $this->uuid($version);
    }
}
