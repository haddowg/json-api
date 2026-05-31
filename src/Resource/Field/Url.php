<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A string attribute that validates URL format by default. Equivalent to
 * `Str::make($name)->url()`.
 */
final class Url extends Str
{
    /**
     * @return static
     */
    public static function make(string $name): static
    {
        $field = new static($name);

        return $field->url();
    }

    /**
     * Restricts the allowed URI schemes (e.g. `https`).
     *
     * @return static
     */
    public function allowedSchemes(string ...$schemes): static
    {
        return $this->url(\array_values($schemes));
    }
}
