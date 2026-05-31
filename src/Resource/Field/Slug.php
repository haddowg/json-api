<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A string attribute that validates a URL slug by default (lowercase
 * alphanumerics separated by single hyphens). Equivalent to
 * `Str::make($name)->slug()`.
 */
final class Slug extends Str
{
    /**
     * @return static
     */
    public static function make(string $name): static
    {
        $field = new static($name);

        return $field->slug();
    }
}
