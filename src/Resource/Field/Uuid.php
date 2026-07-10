<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A **field builder** facade for a UUID string: presets a `UuidFormat` constraint
 * and builds a plain {@see Str}. Equivalent to `Str::make($name)->uuid()`.
 */
final class Uuid extends StrBuilder
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
